# Bảng riêng cho invoice/attempt, CCCD ở order meta

Trạng thái hóa đơn và attempt lưu ở bảng riêng để lọc, tìm, phân trang và có `UNIQUE(order_id)`. CCCD đầy đủ chỉ nằm trong order meta (mã hóa), bảng riêng chỉ giữ bản mask, để privacy exporter/eraser và vòng đời đơn hàng điều khiển dữ liệu nhạy cảm.

Hệ quả: cần migration (`dbDelta`) và quy tắc uninstall; snapshot người mua không được sao chép CCCD đầy đủ vào bảng riêng.
