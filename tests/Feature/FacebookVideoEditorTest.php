<?php

namespace Tests\Feature;

use App\Models\FacebookVideo;
use App\Services\FacebookVideoEditor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dựng video Reel bằng ffmpeg. Test dựng thật chỉ chạy khi máy có ffmpeg kèm drawtext (gói apt của
 * Ubuntu; trên Mac là ffmpeg-full + FFMPEG_BINARY/FFPROBE_BINARY) — CI không có thì bỏ qua.
 */
class FacebookVideoEditorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function video(array $attributes = []): FacebookVideo
    {
        $video = new FacebookVideo($attributes + ['trim_start' => 1.5, 'trim_end' => 1.5]);
        $video->id = 42;

        return $video;
    }

    public function test_wrap_breaks_on_words_and_caps_lines(): void
    {
        $editor = app(FacebookVideoEditor::class);

        $this->assertSame(['Mũ Bảo Hiểm Nửa Đầu Royal', 'M163 Kính Âm'], $editor->wrap('Mũ Bảo Hiểm Nửa Đầu Royal M163 Kính Âm', 3));
        $this->assertSame([], $editor->wrap('   ', 2));

        $lines = $editor->wrap(str_repeat('chữ ', 40), 2);
        $this->assertCount(2, $lines);
        $this->assertStringEndsWith('…', $lines[1]);
    }

    public function test_filter_graph_draws_each_line_from_a_text_file(): void
    {
        $dir = sys_get_temp_dir().'/fb-video-editor-test';
        File::ensureDirectoryExists($dir);
        $video = $this->video(['overlay_top' => "Tên có dấu ' và : lạ", 'overlay_bottom' => 'Chỉ còn 99.000đ']);

        $graph = app(FacebookVideoEditor::class)->filterGraph($video, $dir);

        $this->assertSame(2, substr_count($graph, 'drawtext='));
        // Chữ không nằm trong filtergraph (khỏi escape) mà trong file, tắt expansion để % không bị hiểu nhầm.
        $this->assertStringNotContainsString('Tên có dấu', $graph);
        $this->assertSame("Tên có dấu ' và : lạ", File::get("{$dir}/line-0.txt"));
        $this->assertStringContainsString('expansion=none', $graph);
        $this->assertStringEndsWith('[out]', $graph);

        $plain = app(FacebookVideoEditor::class)->filterGraph($this->video(), $dir);
        $this->assertStringNotContainsString('drawtext', $plain);
        $this->assertStringEndsWith('[base]null[out]', $plain);

        File::deleteDirectory($dir);
    }

    public function test_missing_ffmpeg_is_reported_in_plain_words(): void
    {
        Process::fake(['*' => Process::result(exitCode: 127)]);

        $this->assertStringContainsString('apt install ffmpeg', app(FacebookVideoEditor::class)->problem());
    }

    public function test_renders_a_real_vertical_reel(): void
    {
        $editor = app(FacebookVideoEditor::class);
        if ($problem = $editor->problem()) {
            $this->markTestSkipped($problem);
        }

        $video = $this->video(['overlay_top' => 'Mũ Bảo Hiểm Royal M163', 'overlay_bottom' => 'Chỉ còn 199.000đ']);
        File::ensureDirectoryExists(dirname($video->sourcePath()));
        // Video vuông 6 giây có tiếng, như video sản phẩm Shopee hay gặp.
        Process::run([
            config('services.fb_video.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=480x480:rate=25:duration=6',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=6',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest',
            $video->sourcePath(),
        ])->throw();

        $duration = $editor->render($video);

        $this->assertEqualsWithDelta(3.0, $duration, 0.2);
        $probe = json_decode(Process::run([
            config('services.fb_video.ffprobe'), '-v', 'error', '-show_entries', 'stream=codec_type,width,height,r_frame_rate,sample_rate', '-of', 'json', $video->outputPath(),
        ])->output(), true);
        $streams = collect($probe['streams'])->keyBy('codec_type');
        $this->assertSame(1080, $streams['video']['width']);
        $this->assertSame(1920, $streams['video']['height']);
        $this->assertSame('30/1', $streams['video']['r_frame_rate']);
        $this->assertSame('48000', $streams['audio']['sample_rate']);
        $this->assertDirectoryDoesNotExist(dirname($video->sourcePath()).'/work');
    }

    public function test_too_short_after_trimming_is_refused(): void
    {
        $editor = app(FacebookVideoEditor::class);
        if ($problem = $editor->problem()) {
            $this->markTestSkipped($problem);
        }

        $video = $this->video(['trim_start' => 2, 'trim_end' => 2]);
        File::ensureDirectoryExists(dirname($video->sourcePath()));
        Process::run([
            config('services.fb_video.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=320x320:rate=25:duration=6',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $video->sourcePath(),
        ])->throw();

        $this->expectExceptionMessage('giảm số giây cắt');
        $editor->render($video);
    }
}
