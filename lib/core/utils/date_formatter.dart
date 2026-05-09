import 'package:intl/intl.dart';

class DateFormatter {
  static final _date = DateFormat('dd/MM/yyyy', 'es');
  static final _short = DateFormat('d MMM', 'es');
  static final _time = DateFormat('h:mm a', 'es');

  static String format(DateTime d) => _date.format(d);
  static String shortDate(DateTime d) => _short.format(d);
  static String time(DateTime d) => _time.format(d);

  static int daysUntil(DateTime d) => d.difference(DateTime.now()).inDays;
}
