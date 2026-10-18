import 'package:flutter/material.dart';
import 'package:peopleos/core/widgets/app_top_bar.dart';
import 'package:peopleos/core/widgets/state_views.dart';
import 'package:peopleos/l10n/l10n.dart';

class _SoonInfo {
  const _SoonInfo(this.icon, this.title, this.message);
  final IconData icon;
  final String Function(AppLocalizations l) title;
  final String Function(AppLocalizations l) message;
}

final _features = <String, _SoonInfo>{
  'documents': _SoonInfo(
    Icons.folder_open_outlined,
    (l) => l.appFeatureDocuments,
    (l) => l.appComingSoonDocuments,
  ),
  'more': _SoonInfo(
    Icons.apps_rounded,
    (l) => l.appFeatureMore,
    (l) => l.appComingSoonMore,
  ),
};

final _fallback = _SoonInfo(
  Icons.hourglass_empty_rounded,
  (l) => l.appComingSoonGeneric,
  (l) => l.appComingSoonDefault,
);

/// Friendly placeholder for features whose API is not released yet.
class ComingSoonView extends StatelessWidget {
  const ComingSoonView({super.key, required this.feature});

  final String feature;

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    final info = _features[feature] ?? _fallback;
    return EmptyState(
      icon: info.icon,
      title: l.appComingSoonTitle(info.title(l)),
      message: info.message(l),
    );
  }
}

class ComingSoonScreen extends StatelessWidget {
  const ComingSoonScreen({super.key, required this.feature});

  final String feature;

  @override
  Widget build(BuildContext context) {
    final title = (_features[feature] ?? _fallback).title(context.l10n);
    return SubPage(
      title: title,
      child: ComingSoonView(feature: feature),
    );
  }
}
