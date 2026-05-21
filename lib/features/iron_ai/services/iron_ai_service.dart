import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../../../core/config/app_config.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/ai_message_model.dart';

/// Estado de acceso a IRON IA según la membresía (lo decide el backend).
enum IronAiBannerState {
  freeTrialAvailable,
  freeTrialExhausted,
  membershipAvailable,
  membershipQuotaExhausted,
  premiumLocked,
}

/// CTA devuelto por el backend en estados de bloqueo.
class IronAiCta {
  final String title;
  final String action;
  const IronAiCta({required this.title, required this.action});

  static IronAiCta? fromJson(dynamic json) {
    if (json is! Map) return null;
    return IronAiCta(
      title: (json['title'] ?? 'Ver membresías').toString(),
      action: (json['action'] ?? 'Ver membresías').toString(),
    );
  }
}

/// Acceso/cuota de IRON IA. Todos los límites vienen del backend, NO se
/// hardcodean en Flutter.
class IronAiAccess {
  final bool hasActiveMembership;
  final String? planName;
  final String accessType; // 'free_trial' | 'membership'
  final bool aiEnabled;
  final bool canUseChat;
  final bool upgradeRequired;
  final String contextLevel;
  final bool progressAnalysisEnabled;
  final bool smartRecommendationsEnabled;

  // Prueba gratuita.
  final int usedMessages;
  final int? messageLimit;
  final int? remainingMessages;

  // Membresía.
  final int usedMonth;
  final int? dailyLimit;
  final int? monthlyLimit;
  final int? remainingMonth;

  final IronAiCta? cta;

  const IronAiAccess({
    required this.hasActiveMembership,
    required this.planName,
    required this.accessType,
    required this.aiEnabled,
    required this.canUseChat,
    required this.upgradeRequired,
    required this.contextLevel,
    required this.progressAnalysisEnabled,
    required this.smartRecommendationsEnabled,
    required this.usedMessages,
    required this.messageLimit,
    required this.remainingMessages,
    required this.usedMonth,
    required this.dailyLimit,
    required this.monthlyLimit,
    required this.remainingMonth,
    required this.cta,
  });

  bool get isFreeTrial => accessType == 'free_trial';

  IronAiBannerState get bannerState {
    if (!aiEnabled) return IronAiBannerState.premiumLocked;
    if (isFreeTrial) {
      return canUseChat
          ? IronAiBannerState.freeTrialAvailable
          : IronAiBannerState.freeTrialExhausted;
    }
    return canUseChat
        ? IronAiBannerState.membershipAvailable
        : IronAiBannerState.membershipQuotaExhausted;
  }

  /// Acceso optimista por defecto (si el backend no responde): deja probar.
  factory IronAiAccess.fallback() => const IronAiAccess(
        hasActiveMembership: false,
        planName: null,
        accessType: 'free_trial',
        aiEnabled: true,
        canUseChat: true,
        upgradeRequired: false,
        contextLevel: 'basic',
        progressAnalysisEnabled: false,
        smartRecommendationsEnabled: false,
        usedMessages: 0,
        messageLimit: null,
        remainingMessages: null,
        usedMonth: 0,
        dailyLimit: null,
        monthlyLimit: null,
        remainingMonth: null,
        cta: null,
      );

  factory IronAiAccess.fromJson(Map<String, dynamic> j) {
    int? asInt(dynamic v) => v == null ? null : (v as num).toInt();
    return IronAiAccess(
      hasActiveMembership: j['has_active_membership'] == true,
      planName: j['plan_name']?.toString(),
      accessType: (j['access_type'] ?? 'free_trial').toString(),
      aiEnabled: j['ai_enabled'] != false,
      canUseChat: j['can_use_chat'] != false,
      upgradeRequired: j['upgrade_required'] == true,
      contextLevel: (j['context_level'] ?? 'basic').toString(),
      progressAnalysisEnabled: j['progress_analysis_enabled'] == true,
      smartRecommendationsEnabled: j['smart_recommendations_enabled'] == true,
      usedMessages: asInt(j['used_messages']) ?? 0,
      messageLimit: asInt(j['message_limit']),
      remainingMessages: asInt(j['remaining_messages']),
      usedMonth: asInt(j['used_month']) ?? 0,
      dailyLimit: asInt(j['daily_limit']),
      monthlyLimit: asInt(j['monthly_limit']),
      remainingMonth: asInt(j['remaining_month']),
      cta: IronAiCta.fromJson(j['cta']),
    );
  }

  /// Copia aplicando un snapshot de cuota devuelto por el chat.
  IronAiAccess withQuota(Map<String, dynamic>? quota) {
    if (quota == null) return this;
    int? asInt(dynamic v) => v == null ? null : (v as num).toInt();
    final used = asInt(quota['used']);
    final remaining = asInt(quota['remaining']);
    final limit = asInt(quota['limit']);
    final canUse = remaining == null || remaining > 0;
    return IronAiAccess(
      hasActiveMembership: hasActiveMembership,
      planName: planName,
      accessType: (quota['access_type'] ?? accessType).toString(),
      aiEnabled: aiEnabled,
      canUseChat: aiEnabled && canUse,
      upgradeRequired: !canUse,
      contextLevel: contextLevel,
      progressAnalysisEnabled: progressAnalysisEnabled,
      smartRecommendationsEnabled: smartRecommendationsEnabled,
      usedMessages: isFreeTrial ? (used ?? usedMessages) : usedMessages,
      messageLimit: isFreeTrial ? (limit ?? messageLimit) : messageLimit,
      remainingMessages: isFreeTrial ? (remaining ?? remainingMessages) : remainingMessages,
      usedMonth: isFreeTrial ? usedMonth : (used ?? usedMonth),
      dailyLimit: dailyLimit,
      monthlyLimit: isFreeTrial ? monthlyLimit : (limit ?? monthlyLimit),
      remainingMonth: isFreeTrial ? remainingMonth : (remaining ?? remainingMonth),
      cta: cta,
    );
  }
}

/// Resultado de una respuesta de IRON IA.
class IronAiReply {
  final String reply;
  final String? conversationId;
  final List<String> suggestions;
  final bool isError;

  /// Bloqueo por cuota/membresía (no se llamó a OpenAI).
  final bool blocked;
  final String? code; // FREE_TRIAL_LIMIT_REACHED, etc.
  final bool upgradeRequired;
  final IronAiCta? cta;

  /// Snapshot de cuota devuelto por el backend (used/limit/remaining/access_type).
  final Map<String, dynamic>? quota;

  const IronAiReply({
    required this.reply,
    this.conversationId,
    this.suggestions = const [],
    this.isError = false,
    this.blocked = false,
    this.code,
    this.upgradeRequired = false,
    this.cta,
    this.quota,
  });
}

/// Recomendación inteligente generada por el backend.
class IronAiRecommendation {
  final int id;
  final String type;
  final String title;
  final String message;
  final String status;

  const IronAiRecommendation({
    required this.id,
    required this.type,
    required this.title,
    required this.message,
    required this.status,
  });

  factory IronAiRecommendation.fromJson(Map<String, dynamic> json) =>
      IronAiRecommendation(
        id: (json['id'] as num?)?.toInt() ?? 0,
        type: (json['type'] ?? '').toString(),
        title: (json['title'] ?? '').toString(),
        message: (json['message'] ?? '').toString(),
        status: (json['status'] ?? 'pending').toString(),
      );
}

/// Conversación de IRON IA (una card en el centro de chats).
class IronAiConversation {
  final String uuid;
  final String title;
  final String topic;
  final String? summary;
  final String? lastMessagePreview;
  final int messagesCount;
  final DateTime? lastMessageAt;
  final String status;

  const IronAiConversation({
    required this.uuid,
    required this.title,
    required this.topic,
    required this.summary,
    required this.lastMessagePreview,
    required this.messagesCount,
    required this.lastMessageAt,
    required this.status,
  });

  factory IronAiConversation.fromJson(Map<String, dynamic> j) {
    DateTime? parseDate(dynamic v) =>
        v == null ? null : DateTime.tryParse(v.toString());
    return IronAiConversation(
      uuid: (j['uuid'] ?? '').toString(),
      title: (j['title'] ?? 'Consulta con IRON IA').toString(),
      topic: (j['topic'] ?? 'general').toString(),
      summary: j['summary']?.toString(),
      lastMessagePreview: j['last_message_preview']?.toString(),
      messagesCount: (j['messages_count'] as num?)?.toInt() ?? 0,
      lastMessageAt: parseDate(j['last_message_at']) ?? parseDate(j['created_at']),
      status: (j['status'] ?? 'active').toString(),
    );
  }
}

/// Cliente de IRON IA.
///
/// Arquitectura: Flutter → Laravel → OpenAI. La app solo habla con el backend
/// Laravel; la API key de OpenAI vive únicamente en el backend y NUNCA viaja
/// a Flutter. El usuario actual se identifica de forma flexible enviando su
/// documento (mecanismo de login actual de la app); el backend resuelve el
/// contexto real. Si falla, se devuelve un mensaje amable sin romper la UI.
class IronAiService {
  IronAiService._();
  static final IronAiService instance = IronAiService._();

  static const String friendlyError =
      'IRON IA no está disponible en este momento. Intenta nuevamente en unos minutos.';

  static const Duration _timeout = Duration(seconds: 35);

  Map<String, String> get _headers => const {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      };

  /// Documento del usuario en sesión (la app inicia sesión por documento).
  String? get _document {
    final doc = AppSession.currentUser?.document.trim();
    return (doc == null || doc.isEmpty) ? null : doc;
  }

  /// Consulta el acceso/cuota de IRON IA del usuario actual.
  /// Nunca lanza: si falla devuelve un acceso optimista (deja probar).
  Future<IronAiAccess> fetchAccess() async {
    final query = <String, String>{'document': ?_document};
    final uri = Uri.parse('${AppConfig.apiBase}/iron-ai/access')
        .replace(queryParameters: query.isEmpty ? null : query);
    try {
      final resp = await http.get(uri, headers: _headers).timeout(_timeout);
      final json = _decode(resp);
      if (json.isEmpty) return IronAiAccess.fallback();
      return IronAiAccess.fromJson(json);
    } catch (_) {
      return IronAiAccess.fallback();
    }
  }

  /// Envía un mensaje al asistente dentro de una conversación.
  /// [conversationUuid] null = nuevo chat (el backend crea la conversación y
  /// devuelve su uuid en `reply.conversationId`).
  /// [feature] marca una función premium puntual (p. ej. 'progress_analysis').
  Future<IronAiReply> sendMessage(
    String message, {
    String? conversationUuid,
    String? feature,
  }) async {
    final uri = Uri.parse('${AppConfig.apiBase}/iron-ai/chat');
    try {
      final body = <String, dynamic>{
        'message': message,
        'document': ?_document,
        'conversation_uuid': ?conversationUuid,
        'feature': ?feature,
      };

      final resp = await http
          .post(uri, headers: _headers, body: jsonEncode(body))
          .timeout(_timeout);

      final json = _decode(resp);
      final convUuid = (json['conversation_uuid'] ?? json['conversation_id'])
          as String?;

      final reply = (json['reply'] as String?)?.trim();
      final suggestions = (json['suggestions'] as List?)
              ?.whereType<String>()
              .toList(growable: false) ??
          const <String>[];
      final quota = json['quota'] is Map<String, dynamic>
          ? json['quota'] as Map<String, dynamic>
          : null;

      // Bloqueo por cuota/membresía: el backend responde ok:false + code.
      if (json['ok'] == false && (json['code'] != null)) {
        return IronAiReply(
          reply: reply ?? friendlyError,
          conversationId: convUuid ?? conversationUuid,
          suggestions: suggestions,
          blocked: true,
          code: json['code']?.toString(),
          upgradeRequired: json['upgrade_required'] == true,
          cta: IronAiCta.fromJson(json['cta']),
          quota: quota,
        );
      }

      if (reply == null || reply.isEmpty) {
        return IronAiReply(
          reply: friendlyError,
          conversationId: convUuid ?? conversationUuid,
          isError: true,
          quota: quota,
        );
      }

      return IronAiReply(
        reply: reply,
        conversationId: convUuid ?? conversationUuid,
        suggestions: suggestions,
        quota: quota,
      );
    } catch (_) {
      // Cualquier error (red, timeout, servidor) → mensaje amable, sin romper.
      return IronAiReply(
        reply: friendlyError,
        conversationId: conversationUuid,
        isError: true,
      );
    }
  }

  // ── Conversaciones (centro de chats) ───────────────────────────────────────

  /// Lista las conversaciones activas del usuario. Lanza en error de red para
  /// que la UI muestre estado de error (reintentar).
  Future<List<IronAiConversation>> listConversations() async {
    final query = <String, String>{'document': ?_document};
    final uri = Uri.parse('${AppConfig.apiBase}/iron-ai/conversations')
        .replace(queryParameters: query.isEmpty ? null : query);
    final resp = await http.get(uri, headers: _headers).timeout(_timeout);
    final json = _decode(resp);
    final data = json['data'];
    if (data is! List) return const [];
    return data
        .whereType<Map<String, dynamic>>()
        .map(IronAiConversation.fromJson)
        .toList();
  }

  /// Crea una conversación nueva (no consume cuota).
  Future<IronAiConversation?> createConversation({
    String? title,
    String? topic,
  }) async {
    final uri = Uri.parse('${AppConfig.apiBase}/iron-ai/conversations');
    try {
      final body = <String, dynamic>{
        'document': ?_document,
        'title': ?title,
        'topic': ?topic,
      };
      final resp = await http
          .post(uri, headers: _headers, body: jsonEncode(body))
          .timeout(_timeout);
      final json = _decode(resp);
      final data = json['data'];
      if (data is Map<String, dynamic>) return IronAiConversation.fromJson(data);
      return null;
    } catch (_) {
      return null;
    }
  }

  /// Mensajes reales de una conversación (orden cronológico).
  Future<List<AiMessage>> loadConversationMessages(String uuid) async {
    final query = <String, String>{'document': ?_document};
    final uri = Uri.parse('${AppConfig.apiBase}/iron-ai/conversations/$uuid/messages')
        .replace(queryParameters: query.isEmpty ? null : query);
    try {
      final resp = await http.get(uri, headers: _headers).timeout(_timeout);
      final json = _decode(resp);
      final data = json['data'];
      if (data is! List) return const [];
      return data
          .whereType<Map<String, dynamic>>()
          .map((m) => AiMessage(
                id: '${m['created_at'] ?? DateTime.now().toIso8601String()}-${m['role']}',
                content: (m['content'] ?? '').toString(),
                isUser: (m['role'] ?? '') == 'user',
              ))
          .where((m) => m.content.isNotEmpty)
          .toList();
    } catch (_) {
      return const [];
    }
  }

  Future<bool> archiveConversation(String uuid) =>
      _conversationAction('POST', '/iron-ai/conversations/$uuid/archive');

  Future<bool> clearConversation(String uuid) =>
      _conversationAction('POST', '/iron-ai/conversations/$uuid/clear');

  Future<bool> deleteConversation(String uuid) =>
      _conversationAction('DELETE', '/iron-ai/conversations/$uuid');

  Future<bool> _conversationAction(String method, String path) async {
    final uri = Uri.parse('${AppConfig.apiBase}$path');
    final body = jsonEncode(<String, dynamic>{'document': ?_document});
    try {
      final resp = method == 'DELETE'
          ? await http.delete(uri, headers: _headers, body: body).timeout(_timeout)
          : await http.post(uri, headers: _headers, body: body).timeout(_timeout);
      if (resp.statusCode < 200 || resp.statusCode >= 300) return false;
      final json = _decode(resp);
      return json['ok'] == true;
    } catch (_) {
      return false;
    }
  }

  /// Recomendaciones inteligentes del usuario. Lista vacía si falla o si no
  /// hay usuario identificable.
  Future<List<IronAiRecommendation>> fetchRecommendations() async {
    final query = <String, String>{
      'document': ?_document,
    };
    final uri = Uri.parse('${AppConfig.apiBase}/iron-ai/recommendations')
        .replace(queryParameters: query.isEmpty ? null : query);
    try {
      final resp = await http.get(uri, headers: _headers).timeout(_timeout);
      final json = _decode(resp);
      final data = json['data'];
      if (data is! List) return const [];
      return data
          .whereType<Map<String, dynamic>>()
          .map(IronAiRecommendation.fromJson)
          .where((r) => r.title.isNotEmpty)
          .toList();
    } catch (_) {
      return const [];
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
    return json;
  }
}
