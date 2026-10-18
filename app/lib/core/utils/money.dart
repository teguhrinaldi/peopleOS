/// Exact decimal money. The API sends amounts as strings ("9000000.00");
/// they are kept as integer minor units so no floating-point math is ever
/// involved in display or summing.
class Money implements Comparable<Money> {
  const Money._(this._cents);

  /// Amount in hundredths.
  final BigInt _cents;

  static Money parse(Object? raw) {
    final text = (raw ?? '0').toString().trim();
    if (text.isEmpty) return Money._(BigInt.zero);
    final negative = text.startsWith('-');
    final body = negative ? text.substring(1) : text;
    final parts = body.split('.');
    final whole =
        BigInt.tryParse(parts[0].isEmpty ? '0' : parts[0]) ?? BigInt.zero;
    var frac = parts.length > 1 ? parts[1] : '';
    frac = '${frac}00'.substring(0, 2);
    final cents = whole * BigInt.from(100) + BigInt.parse(frac);
    return Money._(negative ? -cents : cents);
  }

  bool get isNegative => _cents < BigInt.zero;
  bool get isZero => _cents == BigInt.zero;

  Money operator +(Money o) => Money._(_cents + o._cents);
  Money operator -(Money o) => Money._(_cents - o._cents);

  @override
  int compareTo(Money other) => _cents.compareTo(other._cents);

  @override
  bool operator ==(Object other) => other is Money && other._cents == _cents;

  @override
  int get hashCode => _cents.hashCode;

  /// "Rp 9.400.000" / "USD 1,250.50". [decimals] comes from the payslip.
  String format({
    String currency = 'IDR',
    int decimals = 0,
    String locale = 'en',
  }) {
    final abs = _cents.abs();
    final scale = BigInt.from(100);
    var whole = abs ~/ scale;
    var frac = (abs % scale).toInt();
    var fracText = frac.toString().padLeft(2, '0');
    if (decimals == 0) {
      // Round half up to whole units.
      if (frac >= 50) whole += BigInt.one;
      fracText = '';
    } else if (decimals == 1) {
      fracText = (frac / 10).round().clamp(0, 9).toString();
    }
    // Indonesian separators (1.250,50) in the id locale, English otherwise.
    final indonesian = locale == 'id';
    final groupSep = indonesian ? '.' : ',';
    final decimalSep = indonesian ? ',' : '.';
    final digits = whole.toString();
    final buf = StringBuffer();
    for (var i = 0; i < digits.length; i++) {
      if (i > 0 && (digits.length - i) % 3 == 0) buf.write(groupSep);
      buf.write(digits[i]);
    }
    // The symbol follows the currency, never the language.
    final symbol = currency.toUpperCase() == 'IDR' ? 'Rp' : currency;
    final sign =
        _cents.isNegative &&
            !(whole == BigInt.zero && fracText.replaceAll('0', '').isEmpty)
        ? '-'
        : '';
    final fraction = fracText.isEmpty ? '' : '$decimalSep$fracText';
    return '$sign$symbol $buf$fraction';
  }
}
