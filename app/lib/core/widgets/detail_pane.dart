import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Holds the widget shown in the right-hand pane of a tablet master-detail
/// screen (null = nothing selected). Phones show the same widget in a sheet.
class DetailPaneNotifier extends Notifier<Widget?> {
  @override
  Widget? build() => null;

  void show(Widget w) => state = w;
  void clear() => state = null;
}

NotifierProvider<DetailPaneNotifier, Widget?> detailPaneProvider() =>
    NotifierProvider<DetailPaneNotifier, Widget?>(DetailPaneNotifier.new);
