import 'package:intl/intl.dart';

class CurrencyFormatter {
  static final _cop = NumberFormat.currency(
    locale: 'es_CO',
    symbol: '\$',
    decimalDigits: 0,
  );

  static String format(num amount) => _cop.format(amount);
}
