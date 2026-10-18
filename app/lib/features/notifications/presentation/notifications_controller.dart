import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/features/notifications/domain/app_notification.dart';

/// Unread badge on the bell (`GET /notifications/unread-count`).
final unreadCountProvider = FutureProvider<int>((ref) async {
  if (ref.watch(employeeIdProvider) == null) return 0;
  return ref.watch(notificationsRepositoryProvider).unreadCount();
});

@immutable
class InboxState {
  const InboxState({
    this.items = const [],
    this.page = 1,
    this.hasMore = false,
    this.loadingMore = false,
  });

  final List<AppNotification> items;
  final int page;
  final bool hasMore;
  final bool loadingMore;

  int get unread => items.where((n) => !n.isRead).length;

  InboxState copyWith({
    List<AppNotification>? items,
    int? page,
    bool? hasMore,
    bool? loadingMore,
  }) => InboxState(
    items: items ?? this.items,
    page: page ?? this.page,
    hasMore: hasMore ?? this.hasMore,
    loadingMore: loadingMore ?? this.loadingMore,
  );
}

/// Paginated inbox with optimistic read marking.
class InboxController extends AsyncNotifier<InboxState> {
  NotificationsRepository get _repo =>
      ref.read(notificationsRepositoryProvider);

  @override
  Future<InboxState> build() async {
    ref.watch(employeeIdProvider);
    final first = await ref.watch(notificationsRepositoryProvider).list();
    return InboxState(items: first.items, hasMore: first.hasMore);
  }

  Future<void> refresh() async {
    ref.invalidateSelf();
    ref.invalidate(unreadCountProvider);
    await future;
  }

  Future<void> loadMore() async {
    final current = state.value;
    if (current == null || !current.hasMore || current.loadingMore) return;
    state = AsyncData(current.copyWith(loadingMore: true));
    try {
      final next = await _repo.list(page: current.page + 1);
      state = AsyncData(
        current.copyWith(
          items: [...current.items, ...next.items],
          page: current.page + 1,
          hasMore: next.hasMore,
          loadingMore: false,
        ),
      );
    } on Object {
      state = AsyncData(current.copyWith(loadingMore: false));
    }
  }

  /// Marks one notification read. The row flips immediately but the change is
  /// reconciled: if the server refuses, the row goes back to unread.
  Future<void> markRead(AppNotification n) async {
    if (n.isRead) return;
    _replace(n.id, (x) => x.markedRead());
    try {
      await _repo.markRead(n.id);
    } on Object {
      _replace(n.id, (x) => n);
    }
    ref.invalidate(unreadCountProvider);
  }

  /// Returns false (and restores the list) when the server did not confirm.
  Future<bool> markAllRead() async {
    final before = state.value;
    if (before == null) return false;
    try {
      await _repo.markAllRead();
    } on Object {
      return false;
    }
    state = AsyncData(
      before.copyWith(items: [for (final n in before.items) n.markedRead()]),
    );
    ref.invalidate(unreadCountProvider);
    return true;
  }

  void _replace(int id, AppNotification Function(AppNotification) f) {
    final current = state.value;
    if (current == null) return;
    state = AsyncData(
      current.copyWith(
        items: [for (final n in current.items) n.id == id ? f(n) : n],
      ),
    );
  }
}

final inboxControllerProvider =
    AsyncNotifierProvider<InboxController, InboxState>(InboxController.new);
