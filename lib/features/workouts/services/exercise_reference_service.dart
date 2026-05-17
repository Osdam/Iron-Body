import '../../../core/network/api_client.dart';
import '../../../data/models/exercise_reference.dart';

/// Obtiene referencias visuales de ejercicios desde el backend Laravel.
///
/// Arquitectura: Flutter → Laravel → WorkoutX. La app solo conoce a Laravel;
/// la API key de WorkoutX vive únicamente en el backend.
class ExerciseReferenceService {
  ExerciseReferenceService._();
  static final ExerciseReferenceService instance = ExerciseReferenceService._();

  final ApiClient _api = ApiClient.instance;

  /// Caché en memoria por sesión: evita repetir requests al volver a una card.
  final Map<String, List<ExerciseReference>> _cache = {};

  List<ExerciseReference> _parse(Map<String, dynamic> json) {
    final data = json['data'];
    if (data is! List) return const [];
    return data
        .whereType<Map<String, dynamic>>()
        .map(ExerciseReference.fromJson)
        .where((e) => e.name.isNotEmpty)
        .toList();
  }

  /// Devuelve la referencia ya cacheada (sin disparar red) o `null`.
  ExerciseReference? cachedFirst(String name) {
    final list = _cache['s:${name.toLowerCase().trim()}'];
    return (list == null || list.isEmpty) ? null : list.first;
  }

  /// Precalienta la caché para varios ejercicios (al abrir Entrenar), así el
  /// flip muestra el GIF al instante. El backend responde desde su DB/disco
  /// (sin llamar a FitGif), por lo que es rápido y barato.
  Future<void> prewarm(Iterable<String> names) async {
    final seen = <String>{};
    await Future.wait(names
        .where((n) => seen.add(n.toLowerCase().trim()))
        .map((n) => searchByName(n)));
  }

  /// Busca por nombre (acepta español; el backend lo resuelve contra FitGif
  /// y cachea). Devuelve `null` solo si hubo error de red/servidor; lista
  /// vacía significa "sin referencia disponible".
  Future<List<ExerciseReference>?> searchByName(String name) async {
    final key = 's:${name.toLowerCase().trim()}';
    if (_cache.containsKey(key)) return _cache[key];
    try {
      final json = await _api
          .getJson('/exercises/search?q=${Uri.encodeQueryComponent(name)}');
      final list = _parse(json);
      _cache[key] = list;
      return list;
    } on ApiException {
      return null;
    }
  }
}
