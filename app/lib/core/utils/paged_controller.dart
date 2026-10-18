import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/core/utils/paged.dart';

@immutable
class PagedState<T> {
  const PagedState({
    this.items = const [],
    this.page = 1,
    this.hasMore = false,
    this.loadingMore = false,
    this.total,
  });

  final List<T> items;
  final int page;
  final bool hasMore;
  final bool loadingMore;
  final int? total;
}

/// Base for paginated lists: loads page 1 on build, [loadMore] appends the
/// next page (scrolling near the end triggers it, so long lists stay light).
abstract class PagedNotifier<T> extends AsyncNotifier<PagedState<T>> {
  Future<Paged<T>> fetch(int page);

  @override
  Future<PagedState<T>> build() async {
    final first = await fetch(1);
    return PagedState(
      items: first.items,
      hasMore: first.hasMore,
      total: first.total,
    );
  }

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }

  Future<void> loadMore() async {
    final s = state.value;
    if (s == null || !s.hasMore || s.loadingMore) return;
    state = AsyncData(
      PagedState(
        items: s.items,
        page: s.page,
        hasMore: true,
        loadingMore: true,
        total: s.total,
      ),
    );
    try {
      final next = await fetch(s.page + 1);
      state = AsyncData(
        PagedState(
          items: [...s.items, ...next.items],
          page: s.page + 1,
          hasMore: next.hasMore,
          total: next.total ?? s.total,
        ),
      );
    } on Object {
      state = AsyncData(
        PagedState(items: s.items, page: s.page, hasMore: true, total: s.total),
      );
    }
  }
}
