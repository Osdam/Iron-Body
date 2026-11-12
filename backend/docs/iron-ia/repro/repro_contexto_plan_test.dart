// REPRODUCCIÓN (no se añade al repositorio de la app; vive en una copia
// exportada de 27553c3 = la 2.0.9+30 publicada, idéntica a la 2.0.8 en trainer/).
//
// Pantalla, servicio y ApiClient REALES. Solo se sustituye la red: un
// HttpOverrides captura cada petición tal como saldría del teléfono.
import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironbody/features/trainer/models/assigned_member.dart';
import 'package:ironbody/features/trainer/models/member_context.dart';
import 'package:ironbody/features/trainer/models/plan_context.dart';
import 'package:ironbody/features/trainer/screens/integral_assessment_screen.dart';
import 'package:ironbody/features/trainer/widgets/trainer_ui.dart';

class Captura {
  Captura(this.method, this.url, this.body);
  final String method;
  final Uri url;
  final String body;
}

final capturas = <Captura>[];

const respuestaGenerar = {
  'ok': true,
  'data': {
    'status': 'draft',
    'nutrition_guide': {'uuid': 'g-1', 'status': 'draft', 'objective': 'Perder grasa', 'meals_count': 4},
    'routine': {
      'id': 9, 'name': 'Rutina', 'objective': 'Perder grasa', 'level': 'intermedio',
      'days_per_week': 4, 'status': 'Borrador', 'is_assigned': false, 'published': false,
      'generated_by_ai': true, 'exercises_count': 20, 'days': [],
    },
    'analysis': null,
  },
};

class _Overrides extends HttpOverrides {
  @override
  HttpClient createHttpClient(SecurityContext? context) => _Cliente();
}

class _Cliente implements HttpClient {
  @override
  Future<HttpClientRequest> openUrl(String method, Uri url) async => _Peticion(method, url);

  @override
  void close({bool force = false}) {}

  @override
  dynamic noSuchMethod(Invocation i) => super.noSuchMethod(i);
}

class _Cabeceras implements HttpHeaders {
  final _m = <String, List<String>>{};

  @override
  void set(String name, Object value, {bool preserveHeaderCase = false}) =>
      _m[name.toLowerCase()] = [value.toString()];

  @override
  void add(String name, Object value, {bool preserveHeaderCase = false}) =>
      (_m[name.toLowerCase()] ??= []).add(value.toString());

  @override
  void forEach(void Function(String name, List<String> values) action) => _m.forEach(action);

  @override
  String? value(String name) => _m[name.toLowerCase()]?.first;

  @override
  List<String>? operator [](String name) => _m[name.toLowerCase()];

  @override
  dynamic noSuchMethod(Invocation i) => super.noSuchMethod(i);
}

class _Peticion implements HttpClientRequest {
  _Peticion(this.method, this.uri);

  @override
  final String method;
  @override
  final Uri uri;
  final _cuerpo = BytesBuilder();
  final _cabeceras = _Cabeceras();
  final _hecho = Completer<HttpClientResponse>();

  @override
  HttpHeaders get headers => _cabeceras;
  @override
  bool followRedirects = true;
  @override
  int maxRedirects = 5;
  @override
  int contentLength = -1;
  @override
  bool persistentConnection = true;
  @override
  bool bufferOutput = true;
  @override
  Encoding encoding = utf8;

  @override
  void add(List<int> data) => _cuerpo.add(data);

  @override
  Future addStream(Stream<List<int>> stream) => stream.forEach(_cuerpo.add);

  @override
  Future<HttpClientResponse> close() async {
    final cuerpo = utf8.decode(_cuerpo.takeBytes());
    final esApi = uri.path.startsWith('/api/');
    if (esApi) capturas.add(Captura(method, uri, cuerpo));

    final _Respuesta r;
    if (!esApi) {
      r = _Respuesta(400, '{}'); // como el binding de pruebas por defecto (fuentes, etc.)
    } else if (uri.path.endsWith('/integral-plan/generate')) {
      r = _Respuesta(201, jsonEncode(respuestaGenerar));
    } else {
      r = _Respuesta(200, jsonEncode({'ok': true, 'data': []}));
    }
    _hecho.complete(r);

    return r;
  }

  @override
  Future<HttpClientResponse> get done => _hecho.future;

  @override
  dynamic noSuchMethod(Invocation i) => super.noSuchMethod(i);
}

class _Respuesta extends Stream<List<int>> implements HttpClientResponse {
  _Respuesta(this.statusCode, String cuerpo) : _bytes = utf8.encode(cuerpo);

  final List<int> _bytes;
  @override
  final int statusCode;
  @override
  int get contentLength => _bytes.length;
  @override
  HttpHeaders get headers => _Cabeceras()..set('content-type', 'application/json; charset=utf-8');
  @override
  bool get isRedirect => false;
  @override
  bool get persistentConnection => true;
  @override
  String get reasonPhrase => 'OK';
  @override
  List<RedirectInfo> get redirects => const [];
  @override
  HttpClientResponseCompressionState get compressionState =>
      HttpClientResponseCompressionState.notCompressed;

  @override
  StreamSubscription<List<int>> listen(
    void Function(List<int> event)? onData, {
    Function? onError,
    void Function()? onDone,
    bool? cancelOnError,
  }) =>
      Stream<List<int>>.fromIterable([_bytes])
          .listen(onData, onError: onError, onDone: onDone, cancelOnError: cancelOnError);

  @override
  dynamic noSuchMethod(Invocation i) => super.noSuchMethod(i);
}

Finder campo(String etiqueta) => find.descendant(
      of: find.widgetWithText(TrainerTextField, etiqueta),
      matching: find.byType(TextField),
    );

void main() {
  testWidgets('el entrenador escribe 6 días y 45 minutos, pulsa Generar, y viaja {}', (t) async {
    HttpOverrides.global = _Overrides(); // antes de que exista ApiClient.instance
    t.view.physicalSize = const Size(430, 4000);
    t.view.devicePixelRatio = 1.0;
    addTearDown(t.view.reset);

    await t.pumpWidget(const MaterialApp(
      home: IntegralAssessmentScreen(
        token: 'token-ficticio',
        member: AssignedMember(id: 4242, fullName: 'Socio Ficticio', pendingAck: 0),
        // Lo que precarga la ficha: objetivo y nivel del perfil, peso de la valoración.
        memberContext: MemberContext(
          weightKg: 70,
          heightCm: 165,
          objective: 'Perder grasa',
          trainingLevel: 'intermedio',
        ),
      ),
    ));
    await t.pump(const Duration(milliseconds: 50));

    // El caso del incidente, tecleado en el formulario real.
    await t.enterText(campo('Días por semana'), '6');
    await t.enterText(campo('Minutos por sesión'), '45');
    await t.enterText(campo('Lesiones, molestias o limitaciones'), 'Ninguna');
    await t.enterText(campo('Alergias, restricciones o alimentos que evita'), 'Ninguna reportada');
    await t.enterText(campo('Observación del entrenador (opcional)'), 'Ninguna');
    await t.pump();
    expect(find.text('6'), findsWidgets);
    expect(find.text('45'), findsWidgets);

    capturas.clear();
    final boton = find.text('Generar plan con Iron IA');
    await t.ensureVisible(boton);
    await t.tap(boton);
    for (var i = 0; i < 10; i++) {
      await t.pump(const Duration(milliseconds: 50));
    }

    final generar = capturas.where((c) => c.url.path.endsWith('/integral-plan/generate')).toList();
    expect(generar, hasLength(1), reason: 'se pulsó Generar una vez');
    final enviado = generar.single;

    // Lo que habría producido PlanContext.toJson() con esos mismos valores.
    final formulario = PlanContext(
      objective: 'Perder grasa',
      level: 'intermedio',
      daysPerWeek: 6,
      sessionMinutes: 45,
      limitations: 'Ninguna',
      nutritionNotes: 'Ninguna reportada',
      observation: 'Ninguna',
    ).toJson();

    // ignore: avoid_print
    print('[REPRO-APP] ${enviado.method} ${enviado.url.path}');
    // ignore: avoid_print
    print('[REPRO-APP] cuerpo enviado: ${enviado.body}');
    // ignore: avoid_print
    print('[REPRO-APP] PlanContext.toJson() (nunca se llama): ${jsonEncode(formulario)}');
    // ignore: avoid_print
    print('[REPRO-APP] todas las peticiones API: ${capturas.map((c) => '${c.method} ${c.url.path} ${c.body}').toList()}');

    expect(enviado.method, 'POST');
    expect(enviado.url.path, '/api/trainer/members/4242/integral-plan/generate');
    expect(jsonDecode(enviado.body), <String, dynamic>{});
    expect(enviado.body.contains('6'), isFalse);
    expect(enviado.body.contains('45'), isFalse);
    // Ninguna otra petición guardó el contexto antes de generar.
    expect(capturas.where((c) => c.method != 'GET' && c != enviado), isEmpty);
  });
}
