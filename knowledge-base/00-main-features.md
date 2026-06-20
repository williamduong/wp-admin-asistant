# WP Admin Agent - Main Features

Tài liệu này liệt kê các tính năng chính của plugin `wp-admin-assistant` / `WP Admin Agent`.

---

## 1. AI assistant ngay trong `wp-admin`

- Hiển thị chat widget trực tiếp trong trang quản trị WordPress.
- Admin có thể ra lệnh bằng ngôn ngữ tự nhiên thay vì phải đi qua nhiều màn hình settings.
- Hỗ trợ phản hồi theo đúng ngôn ngữ người dùng đang dùng.
- Có thể hoạt động như một trợ lý thao tác, không chỉ là chatbot trả lời text.

## 2. Hỗ trợ nhiều AI provider

- Anthropic Claude
- Google Gemini
- Ollama chạy local / self-hosted

Plugin có cơ chế adapter riêng cho từng provider để chuẩn hóa:

- tin nhắn hội thoại
- tool calling
- usage / token accounting
- model-specific instructions

## 3. Tool calling để thao tác WordPress thật

Assistant không chỉ trả lời văn bản mà có thể gọi tool để thao tác trực tiếp trên site.

Các nhóm tool chính:

- Plugins: liệt kê, cài đặt, kích hoạt, vô hiệu hóa plugin
- Themes: liệt kê, tìm theme, cài theme, đổi theme
- Posts: liệt kê bài viết, tạo bài đơn giản, tạo bài viết giàu nội dung, cập nhật bài viết
- Settings: đọc và cập nhật site settings
- Users: liệt kê users, đổi role user
- Media / Images: tìm ảnh, import ảnh, đặt featured image, đặt site icon
- Navigation / UI: điều hướng người dùng tới đúng trang admin sau khi thao tác
- Security: hardening cơ bản cho WordPress
- Wordfence: đọc scan/settings và cập nhật một số cấu hình
- WooCommerce: đọc trạng thái store, sản phẩm, đơn hàng, coupon, và cập nhật các mục chính

## 3.1. User có thể customize toolset mà AI được phép dùng

Đây là một capability quan trọng nhưng dễ bị bỏ sót.

Trong `Settings -> Admin Agent -> Tools`, admin có thể:

- xem toàn bộ tool đang được register
- bật hoặc tắt từng tool
- enable all / disable all nhanh

Cơ chế hiện tại là:

- tool vẫn tồn tại trong code
- nhưng nếu bị disable, tool đó sẽ không được đưa vào registry mà model nhìn thấy
- vì vậy AI sẽ không được phép gọi tool đó trong runtime bình thường

Điều này cho phép site owner tự giới hạn capability của assistant theo môi trường vận hành, ví dụ:

- chỉ cho đọc mà không cho ghi
- tắt các tool nhạy cảm như plugin/theme/security
- tắt image tools nếu không muốn AI gọi API ngoài
- giới hạn WooCommerce tools trên site không dùng store

## 4. Runtime an toàn cho hành động nhạy cảm

Plugin có confirmation flow riêng cho các hành động có rủi ro.

Các thao tác thường cần xác nhận:

- cài hoặc bật plugin
- cài hoặc đổi theme
- đổi site settings
- đổi quyền user
- đổi site icon
- thao tác bảo mật
- một số thay đổi nội dung như publish, private, trash

Mục tiêu là tránh để model tự ý thực hiện hành động phá hủy hoặc thay đổi lớn trên site.

## 5. Session và conversation persistence

- Mỗi cuộc hội thoại có thể được lưu với `conversation_id`.
- Có thể mở lại session cũ và tiếp tục làm việc.
- Có hỗ trợ title cho conversation.
- Có hỗ trợ archive / restore trong runtime hiện tại.
- Lịch sử được dùng lại làm context cho những lượt chat tiếp theo.

## 6. Streaming realtime qua SSE

- Trả lời AI được stream dần ra UI.
- Trạng thái gọi tool cũng được stream theo event.
- Có trace event để theo dõi từng vòng LLM và từng lần chạy tool.
- Có usage event để hiển thị token, elapsed time, và cost estimate.

Điểm này giúp plugin phản hồi nhanh hơn và làm UI minh bạch hơn khi assistant đang làm việc.

## 6.1. Có hiển thị token usage và cost estimate ngay trong session

Trong runtime hiện tại, plugin có usage tracking theo từng lượt chat:

- `input_tokens`
- `output_tokens`
- `cost_usd`
- `elapsed_ms`

Phần này được hiển thị trong session stats ở UI chat.

Ngoài ra plugin còn lưu token usage vào audit log để thống kê theo:

- provider
- model
- số lần gọi
- tổng input/output tokens
- estimated cost

## 7. Task rail và trạng thái thực thi

Ngoài transcript chat, plugin còn có lớp trạng thái riêng để biểu diễn:

- đang chờ xác nhận
- đang xử lý
- background task đã được queue
- thao tác đã hoàn tất hoặc lỗi

Điều này giúp tránh việc chat text nói "xong rồi" trong khi task nền vẫn đang chạy.

## 8. Guardrails chống hallucination và overclaim

Runtime hiện tại có các guardrails để giảm lỗi kiểu:

- nói là đã xong khi tool bị lỗi
- nói là đã hoàn tất khi task mới chỉ được queue
- khẳng định trạng thái site mà không có kết quả tool tương ứng

Đây là một phần quan trọng của Runtime V1.

## 9. Tạo nội dung tự động cho WordPress

Plugin hỗ trợ nhiều kiểu content workflow:

- tạo post ngắn với `create_simple_post`
- tạo post giàu nội dung với `create_rich_post`
- cập nhật bài viết hiện có
- thêm ảnh vào bài viết hiện có
- dùng RSS feed curated để viết nội dung theo tin mới

`create_rich_post` có thể tự kết hợp phần nội dung và ảnh đại diện theo workflow có sẵn.

## 10. Tìm ảnh và import media

- Tích hợp tìm ảnh từ Pexels
- Download và import vào Media Library
- Gán ảnh đại diện cho bài viết
- Tìm icon và đổi site icon

Plugin đã có lớp fetch/import riêng để kiểm tra mime type, size, và import an toàn hơn.

Điểm thực tế cần lưu ý:

- `search_images` gọi Pexels API rồi import ảnh vào Media Library
- `create_rich_post` cũng có bước auto-fetch ảnh riêng từ Pexels
- vì vậy các workflow có ảnh thường dài hơn, nhiều network hop hơn, và dễ chậm hoặc fail hơn workflow chỉ tạo text
- nếu Pexels key thiếu, API lỗi, hoặc import lỗi, bài vẫn có thể được tạo nhưng không có featured image

## 11. Hỗ trợ Mermaid diagrams trong nội dung

- Có shortcode `[mermaid]...[/mermaid]`
- Hữu ích cho flowchart, sequence diagram, timeline, mindmap, architecture diagram
- Cho phép assistant tạo bài viết có sơ đồ thay vì chỉ có text thuần

## 12. Quản trị WooCommerce bằng hội thoại

Các capability chính hiện có:

- kiểm tra trạng thái WooCommerce
- cập nhật WooCommerce settings cơ bản
- liệt kê sản phẩm
- tạo sản phẩm mới
- cập nhật sản phẩm
- liệt kê đơn hàng
- đổi trạng thái đơn hàng
- tạo coupon

Phần này đủ để plugin xử lý các tác vụ store operation cơ bản ngay trong admin.

## 13. MCP endpoint để expose toolset

- Plugin có endpoint MCP / JSON-RPC riêng
- Các tool đã đăng ký có thể được expose ra lớp MCP
- Giúp mở đường cho tích hợp agent/tooling ngoài UI chat truyền thống

## 14. Logging và audit

- Tool executions được ghi vào bảng `waa_logs`
- Có lưu thông tin provider, model, token usage, kết quả, thời điểm chạy
- Hữu ích cho debug, review hành vi agent, và kiểm tra action history

## 15. Rate limiting và bảo vệ credentials

- REST routes nhạy cảm có rate limit
- API keys được mã hóa khi lưu
- Chỉ user có `manage_options` mới được dùng assistant và endpoints chính

Chi tiết hiện tại trong code:

- rate limit đang hardcode là `30` requests / phút / user
- áp dụng cho các route:
  - chat
  - MCP
  - test connection
- được lưu bằng WordPress transient theo user

Điều này nghĩa là nếu một admin thao tác dồn dập nhiều request liên tiếp trong 1 phút, hệ thống có thể trả về `429 Too Many Requests`.

## 16. Settings UI dành cho vận hành

Trong `Settings -> Admin Agent`, plugin đã có các tab chính:

- Provider & Keys
- Media & Images
- System Prompt
- Tools
- Docs

Admin có thể:

- đổi provider và model
- lưu API keys
- cấu hình Ollama URL
- thêm custom rules vào system prompt
- bật / tắt từng tool khỏi registry mà model được phép dùng
- đọc documentation nội bộ ngay trong WordPress admin

## 17. Prompt runtime có thể tùy biến

System prompt cuối cùng được ghép từ nhiều lớp:

- site context thật của WordPress
- danh sách tool đang khả dụng
- provider-specific instructions
- custom rules do admin thêm vào

Điều này cho phép chỉnh tone, policy, và workflow mà không cần sửa code ở mọi trường hợp nhỏ.

## 18. Dashboard widget cho recent actions

- Có widget trong dashboard admin
- Dùng để xem nhanh các hành động gần đây của agent
- Hữu ích cho monitoring nhẹ trong môi trường demo hoặc internal admin site

## 19. Test stack đa lớp

Plugin đã có hạ tầng test cho nhiều tầng:

- Vitest cho JS và React runtime
- PHPUnit integration tests cho PHP runtime
- Playwright e2e tests cho browser behavior

Các nhóm test hiện tập trung mạnh vào:

- confirmation flow
- runtime safety
- hallucination guards
- session behavior

## 19.1. Token, context window, và cost đang được tính thế nào

Plugin hiện có lớp pricing metadata riêng cho từng provider/model.

Các trường metadata chính:

- `ctx`: context window danh nghĩa của model
- `in`: giá USD / 1M input tokens
- `out`: giá USD / 1M output tokens

Cost estimate hiện được tính theo công thức:

`cost_usd = (input_tokens * in_price + output_tokens * out_price) / 1_000_000`

Nguồn usage hiện tại:

- Anthropic: đọc từ `usage.input_tokens` và `usage.output_tokens`
- Gemini: đọc từ `usageMetadata.promptTokenCount` và `usageMetadata.candidatesTokenCount`
- Ollama: đọc từ `prompt_eval_count` và `eval_count`

Lưu ý quan trọng:

- đây là cost estimate nội bộ theo bảng giá được hardcode trong plugin
- nếu vendor đổi giá hoặc model lifecycle thay đổi, số tiền hiển thị có thể out of date
- Ollama được xem là local/free trong UI vì bảng giá hiện tại đặt `in = 0`, `out = 0`

## 19.2. Giới hạn hiện tại dễ làm bài dài bị dừng giữa chừng

Đây là limitation thực tế của code hiện tại.

Mặc dù bảng pricing lưu context window lớn cho nhiều model, output generation hiện vẫn đang bị giới hạn khá chặt ở provider layer:

- Anthropic: `max_tokens = 4096`
- Gemini: `maxOutputTokens = 4096`
- settings có `waa_max_tokens` với default `4096`, nhưng hiện chưa được dùng để điều khiển provider requests

Hệ quả:

- bài blog dài có thể bị cắt giữa chừng
- flow vừa sinh bài vừa gọi tool ảnh dễ tiêu tốn nhiều context và thời gian hơn
- nếu model cần nhiều vòng tool + text summary, output budget còn lại càng ít
- khi history conversation dài, context cũng bị ăn thêm dù runtime có trimming

Nếu gặp bài dài bị fail hoặc không đủ token, hướng xử lý thực tế hiện nay là:

- dùng model có context lớn hơn và độ ổn định tốt hơn
- chia yêu cầu thành nhiều bước:
  - tạo outline trước
  - tạo từng section sau
  - gắn ảnh ở bước riêng nếu cần
- tránh gộp quá nhiều mục tiêu trong một turn, nhất là vừa viết dài vừa fetch/import ảnh
- bắt đầu session mới nếu history cũ đã quá dài

## 19.3. Những gì docs này muốn phản ánh trung thực

Plugin hiện đã có:

- usage tracking
- cost estimate
- model pricing metadata
- tool customization
- rate limit

Nhưng vẫn còn một số điểm chưa hoàn toàn production-grade:

- pricing metadata có thể bị lỗi thời nếu không cập nhật
- output token cap hiện còn cứng
- workflow dài có ảnh dễ thất bại hơn workflow text-only
- token budgeting chưa phải là policy tinh chỉnh đầy đủ theo từng loại task

## 20. Phù hợp cho các use case chính

Plugin này đặc biệt phù hợp cho:

- quản trị WordPress bằng hội thoại
- thao tác admin lặp đi lặp lại
- content operations có AI hỗ trợ
- WooCommerce admin tasks cơ bản
- internal demo site hoặc sandbox site
- nghiên cứu kiến trúc AI agent nhúng vào WordPress

---

## Tóm tắt ngắn

Nếu cần mô tả ngắn gọn, có thể xem plugin này là:

- một AI admin copilot cho WordPress
- có tool calling thật
- có guardrails cho thao tác nhạy cảm
- có session persistence
- có streaming runtime
- có hỗ trợ content, media, security, WooCommerce, và extensibility qua tool/provider architecture
