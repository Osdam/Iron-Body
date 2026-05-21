import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';

import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/mock/mock_data.dart';
import '../../memberships/screens/memberships_screen.dart';
import '../services/iron_ai_service.dart';
import '../widgets/iron_ai_access_banner.dart';
import '../widgets/iron_ai_conversation_card.dart';
import 'iron_ai_chat_screen.dart';

/// Centro de conversaciones de IRON IA (pantalla principal).
///
/// Muestra saludo, banner de cuota, recomendaciones, chips rápidos y el
/// historial real de conversaciones en cards. Todo viene del backend; nada
/// simulado. Crear/abrir/archivar/eliminar/limpiar se gestionan aquí.
class IronAiHomeScreen extends StatefulWidget {
  const IronAiHomeScreen({super.key});

  @override
  State<IronAiHomeScreen> createState() => _IronAiHomeScreenState();
}

class _IronAiHomeScreenState extends State<IronAiHomeScreen> {
  final _ai = IronAiService.instance;

  IronAiAccess? _access;
  List<IronAiConversation>? _conversations; // null = cargando
  List<IronAiRecommendation> _recommendations = const [];
  bool _error = false;

  // Chip → (mensaje inicial, función premium opcional).
  static const _chips = <String, (String, String?)>{
    'Rutina de hoy': ('Ayúdame con mi rutina de hoy', null),
    'Analizar mi progreso': ('Analiza mi progreso', 'progress_analysis'),
    'Técnica de ejercicio': ('Explícame la técnica correcta de un ejercicio', null),
    'Nutrición general': ('Dame un consejo de nutrición general', null),
    'Motivación': ('Dame una frase de motivación para entrenar hoy', null),
    'Membresía': ('¿Qué incluye mi membresía?', null),
  };

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = false);
    // Acceso y recomendaciones nunca rompen; conversaciones sí marca error.
    _ai.fetchAccess().then((a) {
      if (mounted) setState(() => _access = a);
    });
    _ai.fetchRecommendations().then((r) {
      if (mounted) setState(() => _recommendations = r);
    });
    try {
      final convs = await _ai.listConversations();
      if (!mounted) return;
      setState(() => _conversations = convs);
    } catch (_) {
      if (!mounted) return;
      setState(() => _error = true);
    }
  }

  Future<void> _refreshAfter(Future<dynamic> nav) async {
    await nav;
    if (mounted) _load();
  }

  void _openMemberships() {
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const MembershipsScreen()),
    );
  }

  void _openConversation(IronAiConversation c) {
    _refreshAfter(Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => IronAiChatScreen(
          conversationUuid: c.uuid,
          conversationTitle: c.title,
        ),
      ),
    ));
  }

  void _newChat() {
    _refreshAfter(Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const IronAiChatScreen()),
    ));
  }

  void _openChip(String label) {
    final entry = _chips[label];
    if (entry == null) return;
    _refreshAfter(Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => IronAiChatScreen(
          conversationTitle: label,
          initialMessage: entry.$1,
          initialFeature: entry.$2,
        ),
      ),
    ));
  }

  // ── Acciones de card ────────────────────────────────────────────────────────

  Future<void> _onCardAction(IronAiConversation c, IronAiCardAction action) async {
    switch (action) {
      case IronAiCardAction.open:
        _openConversation(c);
      case IronAiCardAction.clear:
        await _confirmClear(c);
      case IronAiCardAction.archive:
        await _archive(c);
      case IronAiCardAction.delete:
        await _confirmDelete(c);
    }
  }

  Future<void> _archive(IronAiConversation c) async {
    final ok = await _ai.archiveConversation(c.uuid);
    if (!mounted) return;
    if (ok) {
      setState(() => _conversations?.removeWhere((x) => x.uuid == c.uuid));
      _toast('Conversación archivada');
    } else {
      _toast('No pudimos archivar la conversación.');
    }
  }

  Future<void> _confirmClear(IronAiConversation c) async {
    final confirmed = await _confirmDialog(
      title: 'Limpiar chat',
      body:
          'Se eliminarán los mensajes de esta conversación, pero conservarás el chat para iniciar de nuevo.',
      confirmLabel: 'Limpiar',
    );
    if (confirmed != true) return;
    final ok = await _ai.clearConversation(c.uuid);
    if (!mounted) return;
    ok ? _load() : _toast('No pudimos limpiar la conversación.');
    if (ok) _toast('Chat limpiado');
  }

  Future<void> _confirmDelete(IronAiConversation c) async {
    final confirmed = await _confirmDialog(
      title: 'Eliminar conversación',
      body:
          'Esta conversación dejará de aparecer en tu historial. Esta acción no afectará tus demás chats.',
      confirmLabel: 'Eliminar',
      destructive: true,
    );
    if (confirmed != true) return;
    final ok = await _ai.deleteConversation(c.uuid);
    if (!mounted) return;
    if (ok) {
      setState(() => _conversations?.removeWhere((x) => x.uuid == c.uuid));
      _toast('Conversación eliminada');
    } else {
      _toast('No pudimos eliminar la conversación.');
    }
  }

  Future<bool?> _confirmDialog({
    required String title,
    required String body,
    required String confirmLabel,
    bool destructive = false,
  }) {
    return showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface0,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        title: Text(title,
            style: GoogleFonts.lexend(
                fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.textPrimary)),
        content: Text(body,
            style: GoogleFonts.inter(
                fontSize: 13, height: 1.5, color: AppColors.textSecondary)),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text('Cancelar',
                style: GoogleFonts.inter(
                    fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.textSecondary)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(confirmLabel,
                style: GoogleFonts.lexend(
                    fontSize: 13,
                    fontWeight: FontWeight.w800,
                    color: destructive ? AppColors.error : AppColors.textPrimary)),
          ),
        ],
      ),
    );
  }

  void _toast(String msg) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(
        content: Text(msg,
            style: GoogleFonts.inter(color: Colors.white, fontWeight: FontWeight.w600)),
        backgroundColor: AppColors.dark,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ));
  }

  // ── Build ───────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final name = AppSession.currentUser?.firstName;

    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: AppBar(
        backgroundColor: AppColors.surface0,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_ios_new_rounded,
              size: 20, color: AppColors.textPrimary),
          onPressed: () => Navigator.pop(context),
        ),
        title: Row(
          children: [
            SizedBox(
              width: 34,
              height: 34,
              child: Lottie.asset(AppAssets.ironAi, fit: BoxFit.contain),
            ),
            const Gap(8),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('IRON IA',
                    style: GoogleFonts.lexend(
                        fontSize: 16, fontWeight: FontWeight.w800, color: AppColors.textPrimary)),
                Text('Tu asistente inteligente de entrenamiento',
                    style: GoogleFonts.inter(fontSize: 10.5, color: AppColors.textSecondary)),
              ],
            ),
          ],
        ),
      ),
      body: RefreshIndicator(
        color: AppColors.primary,
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 110),
          children: [
            // Saludo
            Text(
              name != null ? 'Hola, $name.' : 'Hola.',
              style: GoogleFonts.lexend(
                  fontSize: 24, fontWeight: FontWeight.w800, color: AppColors.textPrimary),
            ).animate().fadeIn(duration: 350.ms).slideY(begin: 0.15),
            const Gap(2),
            Text('¿En qué quieres trabajar hoy?',
                style: GoogleFonts.inter(fontSize: 14, color: AppColors.textSecondary)),
            const Gap(16),

            // Banner de cuota (todo del backend)
            if (_access != null)
              ClipRRect(
                borderRadius: BorderRadius.circular(14),
                child: IronAiAccessBanner(
                  access: _access!,
                  compact: true,
                  onSeeMemberships: _openMemberships,
                ),
              ).animate().fadeIn(delay: 80.ms),
            const Gap(16),

            // Nuevo chat
            _NewChatButton(onTap: _newChat).animate().fadeIn(delay: 120.ms).slideY(begin: 0.1),
            const Gap(18),

            // Chips rápidos
            Text('Temas rápidos',
                style: GoogleFonts.lexend(
                    fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
            const Gap(10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: _chips.keys
                  .map((label) => _Chip(label: label, onTap: () => _openChip(label)))
                  .toList(),
            ),
            const Gap(20),

            // Recomendaciones (si existen)
            if (_recommendations.isNotEmpty) ...[
              Text('Recomendaciones de IRON',
                  style: GoogleFonts.lexend(
                      fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
              const Gap(10),
              ..._recommendations.take(3).map((r) => _RecommendationCard(rec: r)),
              const Gap(20),
            ],

            // Conversaciones recientes
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text('Conversaciones recientes',
                    style: GoogleFonts.lexend(
                        fontSize: 15, fontWeight: FontWeight.w800, color: AppColors.textPrimary)),
                if (_conversations != null && _conversations!.isNotEmpty)
                  Text('${_conversations!.length}',
                      style: GoogleFonts.inter(
                          fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.textSecondary)),
              ],
            ),
            const Gap(12),
            _buildConversations(),
          ],
        ),
      ),
    );
  }

  Widget _buildConversations() {
    if (_error) {
      return _ErrorState(onRetry: _load);
    }
    if (_conversations == null) {
      return Column(
        children: List.generate(3, (i) => const _SkeletonCard()),
      );
    }
    if (_conversations!.isEmpty) {
      return _EmptyState(onStart: _newChat);
    }
    return Column(
      children: [
        for (var i = 0; i < _conversations!.length; i++)
          IronAiConversationCard(
            conversation: _conversations![i],
            onTap: () => _openConversation(_conversations![i]),
            onAction: (a) => _onCardAction(_conversations![i], a),
          ).animate().fadeIn(delay: (i * 50).ms).slideY(begin: 0.08),
      ],
    );
  }
}

// ── Sub-widgets ───────────────────────────────────────────────────────────────

class _NewChatButton extends StatelessWidget {
  final VoidCallback onTap;
  const _NewChatButton({required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.dark,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
          child: Row(
            children: [
              Container(
                width: 38,
                height: 38,
                decoration: BoxDecoration(
                  color: AppColors.primary,
                  borderRadius: BorderRadius.circular(11),
                ),
                child: const Icon(Icons.add_rounded, color: AppColors.dark, size: 24),
              ),
              const Gap(12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Nuevo chat',
                        style: GoogleFonts.lexend(
                            fontSize: 15, fontWeight: FontWeight.w800, color: AppColors.onDark)),
                    Text('Inicia una conversación con IRON',
                        style: GoogleFonts.inter(
                            fontSize: 11.5, color: AppColors.onDark.withValues(alpha: 0.7))),
                  ],
                ),
              ),
              Icon(Icons.chevron_right_rounded,
                  color: AppColors.primary.withValues(alpha: 0.9), size: 22),
            ],
          ),
        ),
      ),
    );
  }
}

class _Chip extends StatelessWidget {
  final String label;
  final VoidCallback onTap;
  const _Chip({required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.surfaceContainerLow,
      borderRadius: BorderRadius.circular(99),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(99),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(99),
            border: Border.all(color: AppColors.border),
          ),
          child: Text(label,
              style: GoogleFonts.inter(
                  fontSize: 12.5, fontWeight: FontWeight.w500, color: AppColors.textPrimary)),
        ),
      ),
    );
  }
}

class _RecommendationCard extends StatelessWidget {
  final IronAiRecommendation rec;
  const _RecommendationCard({required this.rec});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.primary.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.primary.withValues(alpha: 0.25)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.auto_awesome_rounded, size: 18, color: AppColors.primary),
          const Gap(10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(rec.title,
                    style: GoogleFonts.lexend(
                        fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                const Gap(3),
                Text(rec.message,
                    style: GoogleFonts.inter(
                        fontSize: 12, height: 1.45, color: AppColors.textSecondary)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _SkeletonCard extends StatelessWidget {
  const _SkeletonCard();

  @override
  Widget build(BuildContext context) {
    Widget bar(double w, double h) => Container(
          width: w,
          height: h,
          decoration: BoxDecoration(
            color: AppColors.surfaceContainer,
            borderRadius: BorderRadius.circular(6),
          ),
        );
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: AppColors.surfaceContainer,
              borderRadius: BorderRadius.circular(12),
            ),
          ),
          const Gap(12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [bar(140, 12), const Gap(8), bar(double.infinity, 10)],
            ),
          ),
        ],
      ),
    ).animate(onPlay: (c) => c.repeat()).shimmer(
        duration: 1200.ms, color: AppColors.surfaceVariant.withValues(alpha: 0.5));
  }
}

class _EmptyState extends StatelessWidget {
  final VoidCallback onStart;
  const _EmptyState({required this.onStart});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(22, 28, 22, 24),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        children: [
          Container(
            width: 64,
            height: 64,
            decoration: BoxDecoration(
              color: AppColors.primary.withValues(alpha: 0.12),
              shape: BoxShape.circle,
            ),
            child: const Icon(Icons.forum_rounded, color: AppColors.primary, size: 30),
          ),
          const Gap(16),
          Text('Aún no tienes conversaciones',
              textAlign: TextAlign.center,
              style: GoogleFonts.lexend(
                  fontSize: 16, fontWeight: FontWeight.w800, color: AppColors.textPrimary)),
          const Gap(8),
          Text(
            'Inicia un chat con IRON para recibir ayuda personalizada en rutinas, técnica, progreso o nutrición general.',
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(fontSize: 13, height: 1.5, color: AppColors.textSecondary),
          ),
          const Gap(18),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(
              onPressed: onStart,
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primary,
                foregroundColor: AppColors.dark,
                elevation: 0,
                padding: const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              ),
              child: Text('Iniciar primer chat',
                  style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w800)),
            ),
          ),
        ],
      ),
    );
  }
}

class _ErrorState extends StatelessWidget {
  final VoidCallback onRetry;
  const _ErrorState({required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        children: [
          const Icon(Icons.cloud_off_rounded, color: AppColors.textSecondary, size: 34),
          const Gap(12),
          Text('No pudimos cargar tus conversaciones. Intenta nuevamente.',
              textAlign: TextAlign.center,
              style: GoogleFonts.inter(fontSize: 13, height: 1.5, color: AppColors.textSecondary)),
          const Gap(14),
          OutlinedButton(
            onPressed: onRetry,
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.textPrimary,
              side: const BorderSide(color: AppColors.border),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            ),
            child: Text('Reintentar',
                style: GoogleFonts.lexend(fontSize: 13, fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );
  }
}
