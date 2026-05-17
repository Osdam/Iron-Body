import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config/app_config.dart';

/// Excepción de red/HTTP con un mensaje apto para mostrar al usuario.
class ApiException implements Exception {
  final String message;
  final int? statusCode;
  const ApiException(this.message, {this.statusCode});

  bool get isNetwork => statusCode == null;

  @override
  String toString() => 'ApiException($statusCode): $message';
}

/// Cliente HTTP mínimo para hablar con el backend Laravel.
///
/// No guarda ni registra datos sensibles. Solo intercambia JSON con el backend;
/// la app nunca toca a ePayco directamente ni maneja llaves.
class ApiClient {
  ApiClient._();
  static final ApiClient instance = ApiClient._();

  static const Duration _timeout = Duration(seconds: 20);

  Future<Map<String, dynamic>> postJson(
    String path,
    Map<String, dynamic> body,
  ) async {
    final uri = Uri.parse('${AppConfig.apiBase}$path');
    try {
      final resp = await http
          .post(
            uri,
            headers: const {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
            },
            body: jsonEncode(body),
          )
          .timeout(_timeout);
      return _decode(resp);
    } on TimeoutException {
      throw const ApiException(
          'Tiempo de espera agotado. Revisa tu conexión.');
    } on ApiException {
      rethrow;
    } catch (_) {
      throw const ApiException(
          'No pudimos conectar con el servidor. Revisa tu conexión.');
    }
  }

  Future<Map<String, dynamic>> getJson(String path) async {
    final uri = Uri.parse('${AppConfig.apiBase}$path');
    try {
      final resp = await http.get(
        uri,
        headers: const {'Accept': 'application/json'},
      ).timeout(_timeout);
      return _decode(resp);
    } on TimeoutException {
      throw const ApiException(
          'Tiempo de espera agotado. Revisa tu conexión.');
    } on ApiException {
      rethrow;
    } catch (_) {
      throw const ApiException(
          'No pudimos conectar con el servidor. Revisa tu conexión.');
    }
  }

  Map<String, dynamic> _decode(http.Response resp) {
    Map<String, dynamic> json = {};
    if (resp.body.isNotEmpty) {
      try {
        final decoded = jsonDecode(resp.body);
        if (decoded is Map<String, dynamic>) json = decoded;
      } catch (_) {/* respuesta no-JSON */}
    }
    if (resp.statusCode >= 200 && resp.statusCode < 300) {
      return json;
    }
    // NUNCA se reenvía el mensaje crudo del servidor (puede traer SQL, rutas,
    // queries). Se mapea a un texto amable según el código de estado.
    final friendly = resp.statusCode == 422
        ? 'Revisa los datos de pago e intenta nuevamente.'
        : 'No pudimos procesar el pago. No se realizó ningún cobro. '
            'Intenta nuevamente o usa otro método.';
    throw ApiException(friendly, statusCode: resp.statusCode);
  }
}
