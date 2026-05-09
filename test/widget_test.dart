import 'package:flutter_test/flutter_test.dart';
import 'package:ironbody/main.dart';

void main() {
  testWidgets('WelcomeScreen smoke test', (WidgetTester tester) async {
    await tester.pumpWidget(const IronBodyApp());
    await tester.pump();
    expect(find.text('INICIAR SESIÓN'), findsOneWidget);
    expect(find.text('CREAR CUENTA'), findsOneWidget);
  });
}
