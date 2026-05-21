import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/notification_model.dart';
import '../../../features/iron_ai/services/iron_ai_service.dart';
import '../../../shared/widgets/iron_app_bar.dart';
import '../../../shared/widgets/iron_card.dart';

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  String _filter = 'Todas';
  late List<NotificationModel> _notifications;

  final _filters = ['Todas', 'Pagos', 'Clases', 'Sistema', 'Entrenador', 'Promociones'];

  @override
  void initState() {
    super.initState();
    _notifications = mockNotifications;
    _loadIronRecommendations();
  }

  /// Recomendaciones inteligentes de IRON IA (base inicial, sin push).
  /// Error-safe: si falla o no hay, la pantalla queda igual.
  Future<void> _loadIronRecommendations() async {
    final recs = await IronAiService.instance.fetchRecommendations();
    if (!mounted || recs.isEmpty) return;
    final mapped = recs.map((r) => NotificationModel(
          id: 'iron-${r.id}',
          title: r.title,
          body: r.message,
          type: _typeFor(r.type),
          createdAt: DateTime.now(),
        ));
    setState(() => _notifications = [...mapped, ..._notifications]);
  }

  NotificationType _typeFor(String type) {
    switch (type) {
      case 'membership':
      case 'reminder':
        return NotificationType.payment;
      case 'class':
        return NotificationType.classes;
      default:
        return NotificationType.system;
    }
  }

  List<NotificationModel> get _filtered {
    if (_filter == 'Todas') return _notifications;
    final typeMap = {
      'Pagos': NotificationType.payment,
      'Clases': NotificationType.classes,
      'Sistema': NotificationType.system,
      'Entrenador': NotificationType.trainer,
      'Promociones': NotificationType.promo,
    };
    final type = typeMap[_filter];
    return type == null ? _notifications : _notifications.where((n) => n.type == type).toList();
  }

  void _markAllRead() => setState(() { for (final n in _notifications) n.isRead = true; });

  @override
  Widget build(BuildContext context) {
    final unread = _notifications.where((n) => !n.isRead).length;
    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: IronAppBar(
        title: 'Notificaciones',
        actions: [
          if (unread > 0)
            TextButton(
              onPressed: _markAllRead,
              child: Text('Leer todo', style: GoogleFonts.inter(fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.textSecondary)),
            ),
        ],
      ),
      body: Column(
        children: [
          SizedBox(
            height: 44,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 20),
              itemCount: _filters.length,
              separatorBuilder: (_, __) => const Gap(8),
              itemBuilder: (_, i) {
                final f = _filters[i];
                final active = f == _filter;
                return GestureDetector(
                  onTap: () => setState(() => _filter = f),
                  child: AnimatedContainer(
                    duration: 200.ms,
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                    decoration: BoxDecoration(
                      color: active ? AppColors.dark : AppColors.surfaceContainerLow,
                      borderRadius: BorderRadius.circular(99),
                      border: Border.all(color: active ? AppColors.dark : AppColors.border),
                    ),
                    child: Text(f, style: GoogleFonts.lexend(fontSize: 12, fontWeight: FontWeight.w700, color: active ? AppColors.onDark : AppColors.textSecondary)),
                  ),
                );
              },
            ),
          ),
          const Gap(12),
          Expanded(
            child: ListView.separated(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 80),
              itemCount: _filtered.length,
              separatorBuilder: (_, __) => const Gap(8),
              itemBuilder: (_, i) {
                final n = _filtered[i];
                return _NotifTile(
                  notification: n,
                  onTap: () => setState(() => n.isRead = true),
                ).animate().fadeIn(delay: (i * 60).ms);
              },
            ),
          ),
        ],
      ),
    );
  }
}

class _NotifTile extends StatelessWidget {
  final NotificationModel notification;
  final VoidCallback onTap;
  const _NotifTile({required this.notification, required this.onTap});

  IconData get _icon => switch (notification.type) {
    NotificationType.payment => Icons.credit_card_rounded,
    NotificationType.classes => Icons.calendar_today_rounded,
    NotificationType.system  => Icons.info_outline_rounded,
    NotificationType.promo   => Icons.local_offer_rounded,
    NotificationType.trainer => Icons.fitness_center_rounded,
  };

  Color get _iconColor => switch (notification.type) {
    NotificationType.payment => AppColors.primary,
    NotificationType.classes => const Color(0xFF0891B2),
    NotificationType.system  => AppColors.textSecondary,
    NotificationType.promo   => const Color(0xFFD97706),
    NotificationType.trainer => const Color(0xFF16A34A),
  };

  @override
  Widget build(BuildContext context) {
    final ago = DateTime.now().difference(notification.createdAt);
    final timeStr = ago.inMinutes < 60
        ? 'Hace ${ago.inMinutes} min'
        : ago.inHours < 24
            ? 'Hace ${ago.inHours} h'
            : 'Hace ${ago.inDays} días';

    return GestureDetector(
      onTap: onTap,
      child: IronCard(
        color: notification.isRead ? AppColors.surface0 : AppColors.primary.withValues(alpha: 0.04),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: _iconColor.withValues(alpha: 0.1),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(_icon, color: _iconColor, size: 20),
            ),
            const Gap(12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(notification.title, style: GoogleFonts.lexend(fontSize: 13, fontWeight: notification.isRead ? FontWeight.w500 : FontWeight.w700, color: AppColors.textPrimary)),
                  const Gap(4),
                  Text(notification.body, style: GoogleFonts.inter(fontSize: 12, height: 1.5, color: AppColors.textSecondary)),
                  const Gap(6),
                  Text(timeStr, style: GoogleFonts.inter(fontSize: 11, color: AppColors.textDisabled)),
                ],
              ),
            ),
            if (!notification.isRead)
              Container(width: 8, height: 8, margin: const EdgeInsets.only(top: 4), decoration: const BoxDecoration(color: AppColors.primary, shape: BoxShape.circle)),
          ],
        ),
      ),
    );
  }
}
