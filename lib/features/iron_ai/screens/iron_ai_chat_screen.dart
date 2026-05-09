import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/ai_message_model.dart';

class IronAiChatScreen extends StatefulWidget {
  const IronAiChatScreen({super.key});

  @override
  State<IronAiChatScreen> createState() => _IronAiChatScreenState();
}

class _IronAiChatScreenState extends State<IronAiChatScreen> {
  final _ctrl = TextEditingController();
  final _scrollCtrl = ScrollController();
  late List<AiMessage> _messages;
  bool _typing = false;

  final _suggestions = [
    'Ayúdame con mi rutina de hoy',
    '¿Cómo hago press banca?',
    '¿Qué plan me recomiendas?',
    '¿Cuándo vence mi membresía?',
    'Recomiéndame una rutina para hipertrofia',
  ];

  final _responses = {
    'rutina':
        'Para hoy tienes asignado Pecho y Tríceps. Te recomiendo comenzar con Press de Banca como ejercicio principal: 4 series de 10 repeticiones. Recuerda calentar 10 minutos antes.',
    'press':
        'El Press de Banca se ejecuta así:\n1. Acuéstate en el banco\n2. Agarra la barra ligeramente más ancho que los hombros\n3. Baja controlado hasta el pecho\n4. Empuja explosivamente hacia arriba\n\nEvita arquear excesivamente la espalda.',
    'plan':
        'Basándome en tu objetivo de hipertrofia muscular, te recomiendo el Plan Trimestral por:\n- Acceso a IRON IA avanzado\n- Rutinas personalizadas\n- 1 clase semanal incluida\n- Mejor relación precio/beneficio',
    'membresía':
        'Tu membresía actual (Plan Mensual) vence en 18 días. Te sugiero renovar pronto para no perder tu acceso. Puedes hacerlo desde la sección de Membresías.',
    'hipertrofia':
        'Para hipertrofia muscular te recomiendo:\n- 5 días por semana\n- Rango de 8-12 repeticiones\n- Progresión de carga semanal\n\nRutina sugerida:\n- Lunes: Pecho y Tríceps\n- Martes: Espalda y Bíceps\n- Miércoles: Piernas\n- Jueves: Hombros\n- Viernes: Full Body',
    'default':
        'Entiendo tu consulta. Soy IRON, tu asistente de entrenamiento IA. Puedo ayudarte con:\n\n- Rutinas y técnica de ejercicios\n- Planes de membresía\n- Seguimiento de progreso\n- Nutrición deportiva básica\n\n¿Qué necesitas específicamente?',
  };

  @override
  void initState() {
    super.initState();
    _messages = [...mockAiWelcome];
  }

  @override
  void dispose() {
    _ctrl.dispose();
    _scrollCtrl.dispose();
    super.dispose();
  }

  Future<void> _send(String text) async {
    if (text.trim().isEmpty) return;
    final userMsg = AiMessage(
      id: DateTime.now().toString(),
      content: text,
      isUser: true,
    );
    setState(() {
      _messages.add(userMsg);
      _typing = true;
      _ctrl.clear();
    });
    _scrollToBottom();

    await Future.delayed(const Duration(milliseconds: 1200));

    final lower = text.toLowerCase();
    String response = _responses['default']!;
    for (final key in _responses.keys) {
      if (lower.contains(key)) {
        response = _responses[key]!;
        break;
      }
    }

    final aiMsg = AiMessage(
      id: '${DateTime.now()}ai',
      content: response,
      isUser: false,
    );
    if (mounted) {
      setState(() {
        _messages.add(aiMsg);
        _typing = false;
      });
    }
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
    return Scaffold(
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
          onPressed: () => Navigator.pop(context),
        ),
        title: Row(
          children: [
            SizedBox(
              width: 40,
              height: 40,
              child: Lottie.asset(AppAssets.ironAi, fit: BoxFit.contain),
            ),
            const Gap(10),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'IRON',
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
          Expanded(
            child: ListView.separated(
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

          if (_messages.length <= 1)
            SizedBox(
              height: 48,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 16),
                itemCount: _suggestions.length,
                separatorBuilder: (_, __) => const Gap(8),
                itemBuilder: (_, i) => GestureDetector(
                  onTap: () => _send(_suggestions[i]),
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
                      _suggestions[i],
                      style: GoogleFonts.inter(
                        fontSize: 12,
                        fontWeight: FontWeight.w500,
                        color: AppColors.textPrimary,
                      ),
                    ),
                  ),
                ),
              ),
            ),
          if (_messages.length <= 1) const Gap(8),

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
                    onSubmitted: _send,
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
        ),
      ],
    );
  }
}
