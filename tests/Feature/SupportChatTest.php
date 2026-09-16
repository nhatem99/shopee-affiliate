<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Setting;
use App\Models\ShopeeOrder;
use App\Models\User;
use App\Notifications\AdminReplyNotification;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Chat hỗ trợ: khách nhắn ở /ho-tro, admin trả lời ở /admin/chats.
 */
class SupportChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_sees_chat_page_with_history(): void
    {
        $user = $this->createUser();
        $chat = app(ChatService::class);
        $chat->sendFromUser($user, 'Cho em hỏi đơn hàng ạ');

        $this->actingAs($user)->get('/ho-tro')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Chat')
                ->has('messages', 1)
                ->where('messages.0.body', 'Cho em hỏi đơn hàng ạ')
                ->where('messages.0.from_admin', false));
    }

    public function test_customer_message_creates_conversation_and_queues_it_for_admin(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->post('/ho-tro/gui', ['body' => '  Tiền hoàn của em đâu ạ  '])
            ->assertRedirect();

        $conversation = ChatConversation::where('user_id', $user->id)->first();

        $this->assertNotNull($conversation);
        // Khoảng trắng thừa bị cắt trước khi lưu.
        $this->assertSame('Tiền hoàn của em đâu ạ', $conversation->messages()->value('body'));
        $this->assertSame(1, $conversation->admin_unread);
        $this->assertNotNull($conversation->last_message_at);
    }

    public function test_empty_message_is_rejected(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->post('/ho-tro/gui', ['body' => '  '])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, ChatConversation::count());
    }

    public function test_opening_the_page_clears_the_unread_badge(): void
    {
        $user = $this->createUser();
        $chat = app(ChatService::class);
        $conversation = $chat->conversationFor($user);
        $chat->sendFromAdmin($conversation, $this->createAdmin(), 'Đơn của bạn đang chờ Shopee xác nhận nhé');

        $this->assertSame(1, $conversation->fresh()->user_unread);

        $this->actingAs($user)->get('/ho-tro')->assertOk();

        $this->assertSame(0, $conversation->fresh()->user_unread);
    }

    public function test_admin_cannot_use_the_customer_chat_page(): void
    {
        $this->actingAs($this->createAdmin())->get('/ho-tro')->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/ho-tro')->assertRedirect('/login');
    }

    public function test_chat_disappears_when_turned_off_in_settings(): void
    {
        Setting::set(ChatService::ENABLED_KEY, '0');
        $user = $this->createUser();

        $this->actingAs($user)->get('/ho-tro')->assertNotFound();
        $this->actingAs($user)->post('/ho-tro/gui', ['body' => 'alo'])->assertNotFound();
    }

    public function test_admin_inbox_lists_waiting_conversations_and_marks_them_read(): void
    {
        $user = $this->createUser(['name' => 'Khách A']);
        $admin = $this->createAdmin();
        app(ChatService::class)->sendFromUser($user, 'Em cần hỗ trợ');

        $conversation = ChatConversation::first();

        $this->actingAs($admin)->get('/admin/chats')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Chats')
                ->has('conversations.data', 1)
                ->where('conversations.data.0.name', 'Khách A')
                ->where('conversations.data.0.unread', 1)
                ->where('active', null));

        $this->actingAs($admin)->get('/admin/chats/'.$conversation->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Chats')
                ->has('active.messages', 1)
                ->where('active.user.name', 'Khách A'));

        $this->assertSame(0, $conversation->fresh()->admin_unread);
    }

    public function test_conversation_without_any_message_is_not_listed(): void
    {
        $user = $this->createUser();
        // Khách bấm vào trang chat rồi thoát: hội thoại được tạo nhưng chưa có tin nào.
        $this->actingAs($user)->get('/ho-tro')->assertOk();

        $this->actingAs($this->createAdmin())->get('/admin/chats')
            ->assertInertia(fn ($page) => $page->has('conversations.data', 0));
    }

    public function test_admin_reply_notifies_the_customer(): void
    {
        $user = $this->createUser();
        $admin = $this->createAdmin();
        app(ChatService::class)->sendFromUser($user, 'Em cần hỗ trợ');
        $conversation = ChatConversation::first();

        // Khách gửi xong rồi rời trang — mốc "đang ngồi xem" lùi lại quá xa để tính là đang xem.
        $conversation->update(['user_read_at' => now()->subMinutes(10)]);

        $this->actingAs($admin)->post('/admin/chats/'.$conversation->id.'/reply', ['body' => 'Đơn đang chờ xác nhận nhé'])
            ->assertRedirect();

        $this->assertSame(1, $conversation->fresh()->user_unread);

        $notification = $user->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame(AdminReplyNotification::class, $notification->type);
        $this->assertSame('/ho-tro', $notification->data['url']);
    }

    public function test_no_bell_while_the_customer_is_watching_the_page(): void
    {
        $user = $this->createUser();
        $chat = app(ChatService::class);
        $chat->sendFromUser($user, 'Em cần hỗ trợ');
        $conversation = ChatConversation::first();
        $conversation->update(['user_read_at' => now()]);

        $chat->sendFromAdmin($conversation->fresh(), $this->createAdmin(), 'Chào bạn');

        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_bell_is_not_repeated_while_one_is_still_unread(): void
    {
        $user = $this->createUser();
        $admin = $this->createAdmin();
        $chat = app(ChatService::class);
        $conversation = $chat->conversationFor($user);

        $chat->sendFromAdmin($conversation->fresh(), $admin, 'Dòng 1');
        $chat->sendFromAdmin($conversation->fresh(), $admin, 'Dòng 2');
        $chat->sendFromAdmin($conversation->fresh(), $admin, 'Dòng 3');

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(3, $conversation->fresh()->user_unread);
    }

    public function test_badge_counts_are_shared_with_the_frontend(): void
    {
        $user = $this->createUser();
        $admin = $this->createAdmin();
        app(ChatService::class)->sendFromUser($user, 'Em cần hỗ trợ');

        // Admin: đếm số KHÁCH đang chờ, không phải số tin nhắn.
        app(ChatService::class)->sendFromUser($user, 'Alo anh ơi');

        $this->actingAs($admin)->get('/admin/dashboard')
            ->assertInertia(fn ($page) => $page->where('chat.unread', 1));

        app(ChatService::class)->sendFromAdmin(ChatConversation::first(), $admin, 'Chào bạn');

        $this->actingAs($user)->get('/')
            ->assertInertia(fn ($page) => $page->where('chat.unread', 1));
    }

    public function test_sidebar_badge_endpoint_returns_just_the_waiting_count(): void
    {
        $user = $this->createUser();
        app(ChatService::class)->sendFromUser($user, 'Em cần hỗ trợ');

        // Phải trả về con số, không phải bị route /chats/{conversation} bắt mất chữ "unread".
        $this->actingAs($this->createAdmin())->getJson('/admin/chats/unread')
            ->assertOk()
            ->assertExactJson(['unread' => 1]);

        $this->actingAs($user)->getJson('/admin/chats/unread')->assertForbidden();
    }

    public function test_customer_cannot_reach_the_admin_inbox(): void
    {
        $user = $this->createUser();
        app(ChatService::class)->sendFromUser($user, 'Em cần hỗ trợ');

        $this->actingAs($user)->get('/admin/chats')->assertForbidden();
        $this->actingAs($user)
            ->post('/admin/chats/'.ChatConversation::first()->id.'/reply', ['body' => 'hack'])
            ->assertForbidden();
    }

    public function test_one_conversation_per_customer(): void
    {
        $user = $this->createUser();
        $chat = app(ChatService::class);

        $chat->sendFromUser($user, 'Tin 1');
        $chat->sendFromUser($user, 'Tin 2');

        $this->assertSame(1, ChatConversation::count());
        $this->assertSame(2, ChatConversation::first()->messages()->count());
        $this->assertSame(2, ChatConversation::first()->admin_unread);
    }

    public function test_deleting_a_user_removes_their_conversation(): void
    {
        $user = $this->createUser();
        app(ChatService::class)->sendFromUser($user, 'Em cần hỗ trợ');

        User::destroy($user->id);

        $this->assertSame(0, ChatConversation::count());
    }

    // ── Ảnh đính kèm ────────────────────────────────────────────────────────────────────

    public function test_customer_can_send_an_image_without_any_text(): void
    {
        Storage::fake('uploads');
        $user = $this->createUser();

        $this->actingAs($user)->post('/ho-tro/gui', ['image' => UploadedFile::fake()->image('don.jpg')])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $message = ChatConversation::first()->messages()->first();

        $this->assertNull($message->body);
        $this->assertNotNull($message->image_path);
        Storage::disk('uploads')->assertExists($message->image_path);
        // Tên file do khách đặt không được thành tên file trên server.
        $this->assertStringNotContainsString('don.jpg', $message->image_path);
    }

    public function test_image_url_is_exposed_to_the_frontend(): void
    {
        Storage::fake('uploads');
        $user = $this->createUser();
        $this->actingAs($user)->post('/ho-tro/gui', ['image' => UploadedFile::fake()->image('don.jpg')]);

        $this->actingAs($user)->get('/ho-tro')
            ->assertInertia(fn ($page) => $page
                ->where('messages.0.body', null)
                ->whereNot('messages.0.image', null));
    }

    public function test_non_image_and_oversized_files_are_rejected(): void
    {
        Storage::fake('uploads');
        $user = $this->createUser();

        $this->actingAs($user)
            ->post('/ho-tro/gui', ['image' => UploadedFile::fake()->create('virus.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors('image');

        $this->actingAs($user)
            ->post('/ho-tro/gui', ['image' => UploadedFile::fake()->image('to.jpg')->size(ChatService::MAX_IMAGE_KB + 1)])
            ->assertSessionHasErrors('image');

        $this->assertSame(0, ChatConversation::count());
    }

    public function test_admin_can_reply_with_an_image(): void
    {
        Storage::fake('uploads');
        $user = $this->createUser();
        $admin = $this->createAdmin();
        app(ChatService::class)->sendFromUser($user, 'Em cần hỗ trợ');
        $conversation = ChatConversation::first();

        $this->actingAs($admin)
            ->post('/admin/chats/'.$conversation->id.'/reply', ['image' => UploadedFile::fake()->image('huong-dan.png')])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull($conversation->messages()->where('from_admin', true)->value('image_path'));
    }

    public function test_image_only_message_reads_as_a_photo_in_previews(): void
    {
        Storage::fake('uploads');
        $user = $this->createUser();
        $this->actingAs($user)->post('/ho-tro/gui', ['image' => UploadedFile::fake()->image('don.jpg')]);

        $this->actingAs($this->createAdmin())->get('/admin/chats')
            ->assertInertia(fn ($page) => $page->where('conversations.data.0.preview', '📷 Đã gửi một ảnh'));
    }

    // ── Gắn tin nhắn với một đơn hàng ───────────────────────────────────────────────────

    public function test_order_context_is_pinned_when_opened_from_the_order_list(): void
    {
        $user = $this->createUser();
        $this->order($user, 'ORDER1', 'Tai nghe bluetooth');

        $this->actingAs($user)->get('/ho-tro?don=ORDER1')
            ->assertInertia(fn ($page) => $page
                ->where('context.order_id', 'ORDER1')
                ->where('context.product_name', 'Tai nghe bluetooth'));

        $this->actingAs($user)->post('/ho-tro/gui', ['body' => 'Đơn này bao giờ có tiền ạ', 'order_id' => 'ORDER1']);

        $this->assertSame('ORDER1', ChatConversation::first()->messages()->value('order_id'));
    }

    public function test_an_order_belonging_to_someone_else_is_not_pinned(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $this->order($other, 'ORDER2');

        $this->actingAs($user)->get('/ho-tro?don=ORDER2')
            ->assertInertia(fn ($page) => $page->where('context', null));

        $this->actingAs($user)->post('/ho-tro/gui', ['body' => 'Cho em hỏi', 'order_id' => 'ORDER2'])
            ->assertSessionHasNoErrors();

        // Tin nhắn vẫn gửi bình thường, chỉ là không gắn mã đơn lạ vào.
        $this->assertNull(ChatConversation::first()->messages()->value('order_id'));
    }

    // ── "Đang gõ" và "Đã xem" ───────────────────────────────────────────────────────────

    public function test_typing_flag_rides_along_with_the_poll_request(): void
    {
        $user = $this->createUser();
        $admin = $this->createAdmin();
        app(ChatService::class)->sendFromUser($user, 'Em cần hỗ trợ');
        $conversation = ChatConversation::first();

        // Nhịp poll bình thường: không đụng gì tới cột "đang gõ".
        $this->actingAs($user)->get('/ho-tro');
        $this->assertNull($conversation->fresh()->user_typing_at);

        $this->actingAs($user)->get('/ho-tro?typing=1');
        $this->assertNotNull($conversation->fresh()->user_typing_at);

        $this->actingAs($admin)->get('/admin/chats/'.$conversation->id)
            ->assertInertia(fn ($page) => $page->where('active.meta.peer_typing', true));

        // Ngừng gõ: frontend vẫn gửi tường minh typing=0 ở mỗi nhịp poll (xem Chat.vue) — mốc cũ
        // KHÔNG được làm mới thêm nữa, để nó tự hết hạn.
        $conversation->update(['user_typing_at' => now()->subMinute()]);
        $this->actingAs($user)->get('/ho-tro?typing=0');
        $this->assertTrue($conversation->fresh()->user_typing_at->lt(now()->subSeconds(30)));
    }

    public function test_typing_expires_so_it_does_not_hang_forever(): void
    {
        $user = $this->createUser();
        $admin = $this->createAdmin();
        app(ChatService::class)->sendFromUser($user, 'Em cần hỗ trợ');
        $conversation = ChatConversation::first();
        $conversation->update(['user_typing_at' => now()->subMinute()]);

        $this->actingAs($admin)->get('/admin/chats/'.$conversation->id)
            ->assertInertia(fn ($page) => $page->where('active.meta.peer_typing', false));
    }

    public function test_customer_sees_that_the_admin_has_read_the_conversation(): void
    {
        $user = $this->createUser();
        $admin = $this->createAdmin();
        app(ChatService::class)->sendFromUser($user, 'Em cần hỗ trợ');
        $conversation = ChatConversation::first();

        // Admin chưa mở lần nào: chưa có gì để hiện "Đã xem".
        $this->actingAs($user)->get('/ho-tro')
            ->assertInertia(fn ($page) => $page->where('meta.peer_read_ts', null));

        $this->actingAs($admin)->get('/admin/chats/'.$conversation->id)->assertOk();

        $this->actingAs($user)->get('/ho-tro')
            ->assertInertia(fn ($page) => $page->whereNot('meta.peer_read_ts', null));
    }

    private function order(User $user, string $orderId, ?string $productName = 'Sản phẩm'): ShopeeOrder
    {
        return ShopeeOrder::create([
            'order_id' => $orderId,
            'item_id' => 'ITEM'.$orderId,
            'model_id' => '',
            'status' => 'completed',
            'net_commission' => 10000,
            'product_name' => $productName,
            'user_id' => $user->id,
            'user_sub_id' => $user->sub_id,
            'ordered_at' => now(),
        ]);
    }
}
