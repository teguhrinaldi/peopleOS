import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Fails when user-visible English text is hard-coded in widgets instead of
/// coming from the ARB catalogue. Heuristic, grep-based: it looks for string
/// literals passed to the usual user-facing parameters (Text, label, title,
/// hint, message, tooltip ...) that contain real words.
///
/// Allowed exceptions are listed explicitly below with the reason.
void main() {
  // Parameters that carry user-visible text.
  final params = [
    'label',
    'title',
    'subtitle',
    'hint',
    'helper',
    'message',
    'tooltip',
    'semanticLabel',
    'text',
    'errorText',
    'helperText',
    'hintText',
    'confirmLabel',
    'cancelLabel',
    'emptyText',
    'actionLabel',
    'headline',
    'trailing',
    'description',
  ];
  final paramRe = RegExp(
    '\\b(?:${params.join('|')}):\\s*\\n?\\s*(?:const\\s+)?([\'"])((?:(?!\\1).)*)\\1',
  );
  final textRe = RegExp(
    r'''\b(?:Text|SelectableText|SnackBar)\(\s*(?:const\s+)?(['"])((?:(?!\1).)*)\1''',
  );

  // file suffix -> exact literals allowed there (with reason).
  const allowed = <String, Set<String>>{
    // Brand / protocol / non-translatable tokens.
    '': {'PeopleOS', 'EN', 'ID', 'AM', 'PM', 'IDR', 'PDF'},
  };

  /// Directories with no user-visible UI strings (server simulation, models
  /// that only carry codes, generated code).
  bool skip(String path) =>
      path.contains('/l10n/generated/') ||
      path.contains('/features/demo/') ||
      path.contains('/core/config/') ||
      path.contains('/core/network/') ||
      path.contains('/core/storage/') ||
      path.endsWith('/main.dart');

  bool looksLikeSentence(String s) {
    if (s.contains(r'$')) {
      // Interpolated: only a problem when it also has several English words.
      final words = RegExp(r'[A-Za-z]{3,}')
          .allMatches(s.replaceAll(RegExp(r'\$\{[^}]*\}|\$\w+'), ''))
          .length;
      return words >= 2;
    }
    return RegExp(r'[A-Za-z]{3,}').hasMatch(s) &&
            !RegExp(r'^[a-z_]+$').hasMatch(s) ||
        RegExp(r'[A-Za-z]+ [A-Za-z]+').hasMatch(s);
  }

  test('no hard-coded user-visible English literals in lib/', () {
    final offenders = <String>[];
    for (final f in Directory(
      'lib',
    ).listSync(recursive: true).whereType<File>()) {
      final path = f.path.replaceAll('\\', '/');
      if (!path.endsWith('.dart') || skip(path)) continue;
      final source = f.readAsStringSync();
      for (final re in [paramRe, textRe]) {
        for (final m in re.allMatches(source)) {
          final literal = m.group(2)!;
          if (literal.isEmpty || !looksLikeSentence(literal)) continue;
          if (allowed.values.any((s) => s.contains(literal))) continue;
          final line = '\n'.allMatches(source.substring(0, m.start)).length + 1;
          offenders.add('$path:$line  "$literal"');
        }
      }
    }
    expect(
      offenders,
      isEmpty,
      reason:
          'Hard-coded UI text (move it to lib/l10n):\n${offenders.join('\n')}',
    );
  });
}
