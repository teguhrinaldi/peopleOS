import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/features/demo/demo_backend.dart';
import 'package:peopleos/features/notifications/domain/app_notification.dart';

class DemoNotificationsRepository implements NotificationsRepository {
  DemoNotificationsRepository(this._backend);

  final DemoBackend _backend;

  @override
  Future<NotificationPage> list({int page = 1}) async {
    final res = await _backend.notifications(page);
    return NotificationPage(
      items: (res['items'] as List)
          .map((m) => AppNotification.fromJson(JsonReader.of(m)))
          .toList(),
      unreadCount: res['unread_count'] as int,
      hasMore: page < (res['last_page'] as int),
    );
  }

  @override
  Future<int> unreadCount() => _backend.unreadCount();

  @override
  Future<void> markRead(int id) => _backend.markRead(id);

  @override
  Future<int> markAllRead() => _backend.markAllRead();
}
