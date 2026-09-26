<template>
    <!-- Chỉ MỘT khối chạy hiệu ứng lướt sáng, phần còn lại là nền chìm đứng yên.
         Bản cũ có animate-pulse ở khung ngoài cộng animate-shimmer ở cả năm khối con:
         sáu chuyển động chồng nhau cho một trạng thái "đang chờ", vừa vượt ngân sách
         chuyển động vừa tốn pin trên máy yếu — mà khách vẫn chỉ đọc ra đúng một nghĩa. -->
    <div class="space-y-4" role="status" aria-live="polite">
        <div class="h-48 rounded-2xl animate-shimmer"></div>
        <div class="h-6 w-3/4 panel"></div>
        <div class="h-4 w-1/2 panel"></div>
        <div class="h-24 panel"></div>
        <div class="h-24 panel"></div>
        <span class="sr-only">Đang tải nội dung...</span>
    </div>
</template>
