import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/models/ai_message_model.dart';
import '../../memberships/screens/memberships_screen.dart';
import '../services/iron_ai_service.dart';
import '../widgets/iron_ai_access_banner.dart';

class IronAiChatScreen extends StatefulWidget {
  /// uuid de la conversación. null = chat nuevo (el backend la crea al enviar
  /// el primer mensaje y devuelve su uuid).
  final String? conversationUuid;
  final String? conversationTitle;

  /// Mensaje (y función) inicial a enviar al abrir (chips rápidos).
  final String? initialMessage;
  final String? initialFeature;

  const IronAiChatScreen({
    super.key,
    this.conversationUuid,
    this.conversationTitle,
    this.initialMessage,
    this.initialFeature,
  });

  @override
  State<IronAiChatScreen> createState() => _IronAiChatScreenState();
}

class _IronAiChatScreenState extends State<IronAiChatScreen> {
  final _ctrl = TextEditingController();
  final _scrollCtrl = ScrollController();
  final _ai = IronAiService.instance;
  final List<AiMessage> _messages = [];
  bool _typing = false;
  bool _sending = false;
  bool _loadingMessages = false;
  bool _changed = false; // se envió/cambió algo → refrescar el home al volver

  // uuid de la conversación actual (se actualiza tras el primer mensaje).
  String? _conversationUuid;

  // Acceso/cuota (lo decide el backend según la membresía). null = cargando.
  IronAiAccess? _access;
  bool _dismissedBlockCard = false;

  // Chips → función premium opcional. "Analiza mi progreso" pide la función
  // progress_analysis para que el backend la valide según la membresía.
  static const _suggestions = <String, String?>{
    'Ayúdame con mi rutina de hoy': null,
    '¿Cómo hago press banca?': null,
    'Analiza mi progreso': 'progress_analysis',
    'Recomiéndame una rutina': null,
    'Explícame mi próximo ejercicio': null,
    'Consejo de nutrición general': null,
  };

  /// El chat está bloqueado por cuota/membresía (no por una función puntual).
  bool get _chatBlocked => _access != null && !_access!.canUseChat;

  @override
  void initState() {
    super.initState();
    _conversationUuid = widget.conversationUuid;
    _loadAccess();
    final initial = widget.initialMessage?.trim();
    if (_conversationUuid != null) {
      _loadMessages();
    } else if (initial == null || initial.isEmpty) {
      // Chat nuevo sin mensaje inicial → saludo de bienvenida + chips.
      _messages.add(AiMessage(
        id: 'welcome',
        content:
            'Hola, soy IRON, tu asistente de entrenamiento.\n\nPregúntame sobre rutinas, técnica de ejercicios, progreso o nutrición general. ¿En qué te ayudo hoy?',
        isUser: false,
      ));
    }
    if (initial != null && initial.isNotEmpty) {
      WidgetsBinding.instance.addPostFrameCallback(
        (_) => _send(initial, feature: widget.initialFeature),
      );
    }
  }

  /// Consulta acceso/cuota al abrir. Error-safe: deja probar si el backend no
  /// responde (IronAiAccess.fallback()).
  Future<void> _loadAccess() async {
    final access = await _ai.fetchAccess();
    if (!mounted) return;
    setState(() => _access = access);
  }

  /// Carga los mensajes reales de esta conversación.
  Future<void> _loadMessages() async {
    setState(() => _loadingMessages = true);
    final history = await _ai.loadConversationMessages(_conversationUuid!);
    if (!mounted) return;
    setState(() {
      _messages
        ..clear()
        ..addAll(history);
      _loadingMessages = false;
    });
    _scrollToBottom();
  }

  void _openMemberships() {
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const MembershipsScreen()),
    );
  }

  @override
  void dispose() {
    _ctrl.dispose();
    _scrollCtrl.dispose();
    super.dispose();
  }

  Future<void> _send(String text, {String? feature}) async {
    final message = text.trim();
    if (message.isEmpty || _sending || _chatBlocked) return;

    final userMsg = AiMessage(
      id: DateTime.now().toString(),
      content: message,
      isUser: true,
    );
    setState(() {
      _messages.add(userMsg);
      _typing = true;
      _sending = true;
      _changed = true;
      _ctrl.clear();
    });
    _scrollToBottom();

    // Flutter → Laravel → OpenAI. Bloqueos y errores ya vienen como respuesta
    // controlada (no rompen la UI).
    final result = await _ai.sendMessage(
      message,
      conversationUuid: _conversationUuid,
      feature: feature,
    );
    _conversationUuid ??= result.conversationId;

    if (!mounted) return;
    setState(() {
      _messages.add(AiMessage(
        id: '${DateTime.now()}ai',
        content: result.reply,
        isUser: false,
      ));
      _typing = false;
      _sending = false;
      // Actualiza el contador/estado con la cuota devuelta por el backend.
      if (result.quota != null && _access != null) {
        _access = _access!.withQuota(result.quota);
        if (_chatBlocked) _dismissedBlockCard = false;
      }
    });
    _scrollToBottom();
  }

  void _scrollToBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scrollCtrl.hasClients) {
        _scrollCtrl.animateTo(
          _scrollCtrl.position.maxScrollExtent,
          duration: 300.ms,
          curve: Curves.easeOut,
        );
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) Navigator.pop(context, _changed);
      },
      child: Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(
        backgroundColor: AppColors.surface0,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(
            Icons.keyboard_arrow_down_rounded,
            size: 28,
            color: AppColors.textPrimary,
          ),
          onPressed: () => Navigator.pop(context, _changed),
        ),
        title: Row(
          children: [
            SizedBox(
              width: 40,
              height: 40,
              child: Lottie.asset(AppAssets.ironAi, fit: BoxFit.contain),
            ),
            const Gap(10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    widget.conversationTitle ?? 'IRON',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: GoogleFonts.lexend(
                      fontSize: 16,
                      fontWeight: FontWeight.w700,
                      color: AppColors.textPrimary,
                    ),
                  ),
                  Text(
                    'Asistente IA de Iron Body',
                    style: GoogleFonts.inter(
                      fontSize: 11,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
      body: Stack(
        children: [
          Positioned.fill(
            child: Image.asset(AppAssets.fondoChat, fit: BoxFit.cover),
          ),
          Positioned.fill(
            child: Container(color: Colors.white.withValues(alpha: 0.12)),
          ),
          Positioned.fill(
            child: Column(
              children: [
          // Estado de acceso/cuota (todo viene del backend, no hardcodeado).
          if (_access != null)
            IronAiAccessBanner(
              access: _access!,
              compact: true,
              onSeeMemberships: _openMemberships,
            ),
          Expanded(
            child: _loadingMessages && _messages.isEmpty
                ? const Center(
                    child: SizedBox(
                      width: 26,
                      height: 26,
                      child: CircularProgressIndicator(
                        strokeWidth: 2.4,
                        valueColor: AlwaysStoppedAnimation<Color>(AppColors.primary),
                      ),
                    ),
                  )
                : ListView.separated(
                    controller: _scrollCtrl,
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
                    itemCount: _messages.length + (_typing ? 1 : 0),
                    separatorBuilder: (_, __) => const Gap(10),
                    itemBuilder: (_, i) {
                      if (i == _messages.length) return _TypingIndicator();
                      final msg = _messages[i];
                      return _Bubble(message: msg)
                          .animate()
                          .fadeIn(delay: 100.ms)
                          .slideY(begin: 0.1);
                    },
                  ),
          ),

          if (_messages.length <= 1 && !_chatBlocked)
            SizedBox(
              height: 48,
              child: Builder(builder: (_) {
                final entries = _suggestions.entries.toList();
                return ListView.separated(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  itemCount: entries.length,
                  separatorBuilder: (_, __) => const Gap(8),
                  itemBuilder: (_, i) => GestureDetector(
                    onTap: () => _send(entries[i].key, feature: entries[i].value),
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 14,
                        vertical: 10,
                      ),
                      decoration: BoxDecoration(
                        color: AppColors.surfaceContainerLow,
                        borderRadius: BorderRadius.circular(99),
                        border: Border.all(color: AppColors.border),
                      ),
                      child: Text(
                        entries[i].key,
                        style: GoogleFonts.inter(
                          fontSize: 12,
                          fontWeight: FontWeight.w500,
                          color: AppColors.textPrimary,
                        ),
                      ),
                    ),
                  ),
                );
              }),
            ),
          if (_messages.length <= 1 && !_chatBlocked) const Gap(8),

          // Bloqueado por cuota/membresía → tarjeta premium o barra deshabilitada.
          // En caso normal → input. Función premium puntual NO bloquea el input.
          if (_chatBlocked && !_dismissedBlockCard)
            IronAiAccessBanner(
              access: _access!,
              compact: false,
              onSeeMemberships: _openMemberships,
              onDismiss: () => setState(() => _dismissedBlockCard = true),
            )
          else if (_chatBlocked)
            _DisabledInputBar(onTap: _openMemberships)
          else
            Container(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 24),
            decoration: const BoxDecoration(
              color: AppColors.surface0,
              border: Border(top: BorderSide(color: AppColors.border)),
            ),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _ctrl,
                    style: GoogleFonts.inter(
                      fontSize: 14,
                      color: AppColors.textPrimary,
                    ),
                    maxLines: null,
                    onSubmitted: (t) => _send(t),
                    decoration: InputDecoration(
                      hintText: 'Escribe tu consulta...',
                      hintStyle: GoogleFonts.inter(
                        fontSize: 14,
                        color: AppColors.textDisabled,
                      ),
                      filled: true,
                      fillColor: AppColors.surfaceContainerLow,
                      contentPadding: const EdgeInsets.symmetric(
                        horizontal: 16,
                        vertical: 12,
                      ),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(24),
                        borderSide: BorderSide.none,
                      ),
                      focusedBorder: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(24),
                        borderSide:
                            const BorderSide(color: AppColors.primary),
                      ),
                    ),
                  ),
                ),
                const Gap(10),
                GestureDetector(
                  onTap: () => _send(_ctrl.text),
                  child: Container(
                    width: 48,
                    height: 48,
                    decoration: const BoxDecoration(
                      color: AppColors.dark,
                      shape: BoxShape.circle,
                    ),
                    child: const Icon(
                      Icons.send_rounded,
                      color: AppColors.primary,
                      size: 22,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
          ),
        ],
      ),
      ),
    );
  }
}

class _Bubble extends StatelessWidget {
  final AiMessage message;
  const _Bubble({required this.message});

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment:
          message.isUser ? MainAxisAlignment.end : MainAxisAlignment.start,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (!message.isUser) ...[
          SizedBox(
            width: 32,
            height: 32,
            child: Lottie.asset(AppAssets.ironAi, fit: BoxFit.contain),
          ),
          const Gap(8),
        ],
        Flexible(
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            decoration: BoxDecoration(
              color: message.isUser
                  ? AppColors.dark
                  : AppColors.surfaceContainerLow,
              borderRadius: BorderRadius.only(
                topLeft: const Radius.circular(18),
                topRight: const Radius.circular(18),
                bottomLeft: Radius.circular(message.isUser ? 18 : 4),
                bottomRight: Radius.circular(message.isUser ? 4 : 18),
              ),
            ),
            child: Text(
              message.content,
              style: GoogleFonts.inter(
                fontSize: 14,
                height: 1.5,
                color: message.isUser ? AppColors.onDark : AppColors.textPrimary,
              ),
            ),
          ),
        ),
        if (message.isUser) ...[
          const Gap(8),
          const CircleAvatar(
            radius: 16,
            backgroundColor: AppColors.primary,
            child: Icon(Icons.person, size: 16, color: AppColors.dark),
          ),
        ],
      ],
    );
  }
}

/// Barra deshabilitada que reemplaza el input cuando IRON IA está bloqueado y
/// el usuario cerró la tarjeta ("Más tarde"). Tocarla abre Membresías.
class _DisabledInputBar extends StatelessWidget {
  final VoidCallback onTap;
  const _DisabledInputBar({required this.onTap});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 26),
        decoration: const BoxDecoration(
          color: AppColors.surface0,
          border: Border(top: BorderSide(color: AppColors.border)),
        ),
        child: Row(
          children: [
            const Icon(Icons.lock_rounded, size: 18, color: AppColors.textDisabled),
            const Gap(10),
            Expanded(
              child: Text(
                'IRON IA bloqueado. Compra una membresía para continuar.',
                style: GoogleFonts.inter(
                  fontSize: 13,
                  fontWeight: FontWeight.w500,
                  color: AppColors.textSecondary,
                ),
              ),
            ),
            const Gap(8),
            Text(
              'Ver membresías',
              style: GoogleFonts.lexend(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: AppColors.primary,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _TypingIndicator extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        SizedBox(
          width: 32,
          height: 32,
          child: Lottie.asset(AppAssets.ironAi, fit: BoxFit.contain),
        ),
        const Gap(8),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          decoration: const BoxDecoration(
            color: AppColors.surfaceContainerLow,
            borderRadius: BorderRadius.only(
              topLeft: Radius.circular(18),
              topRight: Radius.circular(18),
              bottomRight: Radius.circular(18),
              bottomLeft: Radius.circular(4),
            ),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                mainAxisSize: MainAxisSize.min,
                children: List.generate(
                  3,
                  (i) => Container(
                    width: 7,
                    height: 7,
                    margin: const EdgeInsets.symmetric(horizontal: 2),
                    decoration: const BoxDecoration(
                      color: AppColors.textDisabled,
                      shape: BoxShape.circle,
                    ),
                  )
                      .animate(onPlay: (c) => c.repeat())
                      .then(delay: (i * 200).ms)
                      .moveY(
                        begin: 0,
                        end: -4,
                        duration: 400.ms,
                        curve: Curves.easeInOut,
                      )
                      .then()
                      .moveY(
                        begin: -4,
                        end: 0,
                        duration: 400.ms,
                        curve: Curves.easeInOut,
                      ),
                ),
              ),
              const Gap(10),
              Text(
                'IRON está pensando…',
                style: GoogleFonts.inter(
                  fontSize: 12,
                  fontWeight: FontWeight.w500,
                  color: AppColors.textSecondary,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
