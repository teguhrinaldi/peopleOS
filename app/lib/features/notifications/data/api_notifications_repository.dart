import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/features/notifications/domain/app_notification.dart';

class ApiNotificationsRepository implements NotificationsRepository {
  ApiNotificationsRepository(this._client);

  final ApiClient _client;

  @override
  Future<NotificationPage> list({int page = 1}) async {
    final res = await _client.get(
      '/notifications',
      query: {'page': page, 'per_page': 20},
    );
    final lastPage = res.meta['last_page'];
    return NotificationPage(
      items: res.list
          .map((e) => AppNotification.fromJson(JsonReader.of(e)))
          .toList(),
      unreadCount: (res.meta['unread_count'] as num?)?.toInt() ?? 0,
      hasMore: lastPage is num && page < lastPage,
    );
  }

  @override
  Future<int> unreadCount() async {
    final res = await _client.get('/notifications/unread-count');
    return JsonReader.of(res.data).intOr('unread_count', 0);
  }

  @override
  Future<void> markRead(int id) async {
    await _client.post('/notifications/$id/read');
  }

  @override
  Future<int> markAllRead() async {
    final res = await _client.post('/notifications/read-all');
    return JsonReader.of(res.data).intOr('marked', 0);
  }
}
