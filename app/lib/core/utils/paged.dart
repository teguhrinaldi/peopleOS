/// One page of a paginated API list (`meta.page`, `meta.last_page`).
class Paged<T> {
  const Paged({
    required this.items,
    this.page = 1,
    this.lastPage = 1,
    this.total,
  });

  final List<T> items;
  final int page;
  final int lastPage;
  final int? total;

  bool get hasMore => page < lastPage;
}

/// Downloaded binary (PDF etc.) held in memory until saved or shared.
class DownloadedFile {
  const DownloadedFile({
    required this.bytes,
    required this.fileName,
    this.mimeType = 'application/pdf',
  });

  final List<int> bytes;
  final String fileName;
  final String mimeType;
}
