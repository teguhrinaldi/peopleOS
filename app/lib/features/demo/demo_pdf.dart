import 'dart:convert';
import 'dart:typed_data';

/// Builds a small, valid single-page PDF (Helvetica text only) so Demo mode
/// can offer a real downloadable file without a PDF dependency or an asset.
Uint8List buildSimplePdf({required String title, required List<String> lines}) {
  String esc(String s) => s
      .replaceAll(r'\', r'\\')
      .replaceAll('(', r'\(')
      .replaceAll(')', r'\)')
      .replaceAll(RegExp(r'[^\x20-\x7E]'), '?');

  final content = StringBuffer()
    ..writeln('BT')
    ..writeln('/F2 18 Tf 56 780 Td (${esc(title)}) Tj')
    ..writeln('/F1 11 Tf 0 -30 Td 16 TL');
  for (final line in lines) {
    content.writeln('(${esc(line)}) Tj T*');
  }
  content.writeln('ET');
  final stream = content.toString();

  final objects = <String>[
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
        '/Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    '<< /Length ${stream.length} >>\nstream\n$stream\nendstream',
  ];

  final out = StringBuffer('%PDF-1.4\n');
  final offsets = <int>[];
  for (var i = 0; i < objects.length; i++) {
    offsets.add(out.length);
    out.write('${i + 1} 0 obj\n${objects[i]}\nendobj\n');
  }
  final xref = out.length;
  out.write('xref\n0 ${objects.length + 1}\n0000000000 65535 f \n');
  for (final o in offsets) {
    out.write('${o.toString().padLeft(10, '0')} 00000 n \n');
  }
  out.write(
    'trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n',
  );
  // Everything is ASCII, so string length equals byte length.
  return Uint8List.fromList(latin1.encode(out.toString()));
}
