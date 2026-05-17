/// Referencia visual de un ejercicio (GIF + metadatos).
///
/// La app NUNCA habla con WorkoutX: este modelo viene siempre del backend
/// Laravel, que normaliza la respuesta del proveedor a esta forma estable.
class ExerciseReference {
  final String externalId;
  final String name;
  final String? bodyPart;
  final String? target;
  final String? equipment;
  final String? gifUrl;

  /// 2.º frame del movimiento (FitGIF / Free Exercise DB) o miniatura.
  /// Si existe, Flutter alterna gifUrl↔thumbnailUrl para dar fluidez.
  final String? thumbnailUrl;
  /// MP4 optimizado (1.3x, H.264) servido por el backend. Si existe se
  /// reproduce con video_player (más rápido/fluido); el GIF queda de fallback.
  final String? videoUrl;

  /// 'video' si hay MP4, 'gif' en otro caso.
  final String mediaType;
  final List<String> instructions;
  final String provider;

  /// Licencia / origen del recurso (transparencia en UI).
  final String? source;

  const ExerciseReference({
    required this.externalId,
    required this.name,
    this.bodyPart,
    this.target,
    this.equipment,
    this.gifUrl,
    this.thumbnailUrl,
    this.videoUrl,
    this.mediaType = 'gif',
    this.instructions = const [],
    this.provider = 'workoutx',
    this.source,
  });

  bool get hasGif => (gifUrl != null && gifUrl!.trim().isNotEmpty);

  bool get hasVideo => (videoUrl != null && videoUrl!.trim().isNotEmpty);

  /// Hay 2 frames → se puede animar el movimiento por cross-fade.
  bool get hasFrames =>
      hasGif && thumbnailUrl != null && thumbnailUrl!.trim().isNotEmpty;

  String get shortInstruction {
    if (instructions.isEmpty) return '';
    return instructions.first.trim();
  }

  static String? _str(dynamic v) {
    if (v == null) return null;
    final s = v.toString().trim();
    return s.isEmpty ? null : s;
  }

  factory ExerciseReference.fromJson(Map<String, dynamic> json) {
    final raw = json['instructions'];
    final steps = <String>[];
    if (raw is List) {
      for (final e in raw) {
        final s = e?.toString().trim() ?? '';
        if (s.isNotEmpty) steps.add(s);
      }
    } else if (raw is String && raw.trim().isNotEmpty) {
      steps.add(raw.trim());
    }

    return ExerciseReference(
      externalId: _str(json['external_id']) ?? '',
      name: _str(json['name']) ?? '',
      bodyPart: _str(json['body_part']),
      target: _str(json['target']),
      equipment: _str(json['equipment']),
      gifUrl: _str(json['gif_url']),
      thumbnailUrl: _str(json['thumbnail_url']),
      videoUrl: _str(json['video_url']),
      mediaType: _str(json['media_type']) ?? 'gif',
      instructions: steps,
      provider: _str(json['provider']) ?? 'workoutx',
      source: _str(json['source']),
    );
  }
}
