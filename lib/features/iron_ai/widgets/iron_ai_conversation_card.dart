import 'package:flutter/material.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../../core/theme/app_colors.dart';
import '../services/iron_ai_service.dart';

enum IronAiCardAction { open, clear, archive, delete }

/// Metadatos visuales por tema de conversación.
class _TopicMeta {
  final IconData icon;
  final Color color;
  final String label;
  const _TopicMeta(this.icon, this.color, this.label);
}

_TopicMeta _topicMeta(String topic) {
  switch (topic) {
    case 'routine':
      return const _TopicMeta(Icons.fitness_center_rounded, Color(0xFF16A34A), 'Rutina');
    case 'progress':
      return const _TopicMeta(Icons.insights_rounded, Color(0xFF0891B2), 'Progreso');
    case 'technique':
      return const _TopicMeta(Icons.sports_gymnastics_rounded, Color(0xFF7C3AED), 'Técnica');
    case 'nutrition':
      return const _TopicMeta(Icons.restaurant_rounded, Color(0xFFD97706), 'Nutrición');
    case 'care':
      return const _TopicMeta(Icons.healing_rounded, Color(0xFFDC2626), 'Cuidado');
    case 'membership':
      return const _TopicMeta(Icons.workspace_premium_rounded, Color(0xFFCA8A04), 'Membresía');
    case 'motivation':
      return const _TopicMeta(Icons.bolt_rounded, Color(0xFFEA580C), 'Motivación');
    default:
      return const _TopicMeta(Icons.chat_bubble_rounded, Color(0xFF475569), 'General');
  }
}

/// Card premium de una conversación con menú de acciones (3 puntos).
class IronAiConversationCard extends StatelessWidget {
  final IronAiConversation conversation;
  final VoidCallback onTap;
  final ValueChanged<IronAiCardAction> onAction;

  const IronAiConversationCard({
    super.key,
    required this.conversation,
    required this.onTap,
    required this.onAction,
  });

  String _timeAgo(DateTime? d) {
    if (d == null) return '';
    final diff = DateTime.now().difference(d);
    if (diff.inMinutes < 1) return 'Ahora';
    if (diff.inMinutes < 60) return 'Hace ${diff.inMinutes} min';
    if (diff.inHours < 24) return 'Hace ${diff.inHours} h';
    if (diff.inDays < 7) return 'Hace ${diff.inDays} d';
    return '${d.day}/${d.month}/${d.year}';
  }

  @override
  Widget build(BuildContext context) {
    final meta = _topicMeta(conversation.topic);
    final preview = (conversation.lastMessagePreview ?? conversation.summary ?? '')
        .replaceAll('\n', ' ')
        .trim();

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
        boxShadow: [
          BoxShadow(
            color: AppColors.dark.withValues(alpha: 0.04),
            blurRadius: 12,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(16),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 13, 6, 13),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 44,
                  height: 44,
                  decoration: BoxDecoration(
                    color: meta.color.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Icon(meta.icon, color: meta.color, size: 22),
                ),
                const Gap(12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              conversation.title,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: GoogleFonts.lexend(
                                fontSize: 14,
                                fontWeight: FontWeight.w700,
                                color: AppColors.textPrimary,
                              ),
                            ),
                          ),
                          const Gap(6),
                          Text(
                            _timeAgo(conversation.lastMessageAt),
                            style: GoogleFonts.inter(
                                fontSize: 10.5, color: AppColors.textDisabled),
                          ),
                        ],
                      ),
                      const Gap(4),
                      Text(
                        preview.isEmpty ? 'Sin mensajes todavía' : preview,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: GoogleFonts.inter(
                          fontSize: 12.5,
                          height: 1.4,
                          color: preview.isEmpty
                              ? AppColors.textDisabled
                              : AppColors.textSecondary,
                        ),
                      ),
                      const Gap(8),
                      Row(
                        children: [
                          _TopicChip(meta: meta),
                          const Gap(8),
                          Icon(Icons.chat_bubble_outline_rounded,
                              size: 12, color: AppColors.textDisabled),
                          const Gap(3),
                          Text(
                            '${conversation.messagesCount}',
                            style: GoogleFonts.inter(
                                fontSize: 11, color: AppColors.textDisabled),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                _ActionMenu(onAction: onAction),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _TopicChip extends StatelessWidget {
  final _TopicMeta meta;
  const _TopicChip({required this.meta});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: meta.color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(99),
      ),
      child: Text(
        meta.label,
        style: GoogleFonts.inter(
            fontSize: 10.5, fontWeight: FontWeight.w600, color: meta.color),
      ),
    );
  }
}

class _ActionMenu extends StatelessWidget {
  final ValueChanged<IronAiCardAction> onAction;
  const _ActionMenu({required this.onAction});

  @override
  Widget build(BuildContext context) {
    return PopupMenuButton<IronAiCardAction>(
      icon: const Icon(Icons.more_vert_rounded, size: 20, color: AppColors.textSecondary),
      color: AppColors.surface0,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      onSelected: onAction,
      itemBuilder: (_) => [
        _item(IronAiCardAction.open, Icons.north_east_rounded, 'Abrir'),
        _item(IronAiCardAction.clear, Icons.cleaning_services_rounded, 'Limpiar chat'),
        _item(IronAiCardAction.archive, Icons.archive_outlined, 'Archivar'),
        _item(IronAiCardAction.delete, Icons.delete_outline_rounded, 'Eliminar',
            color: AppColors.error),
      ],
    );
  }

  PopupMenuItem<IronAiCardAction> _item(
    IronAiCardAction value,
    IconData icon,
    String label, {
    Color? color,
  }) {
    return PopupMenuItem<IronAiCardAction>(
      value: value,
      height: 44,
      child: Row(
        children: [
          Icon(icon, size: 18, color: color ?? AppColors.textSecondary),
          const Gap(12),
          Text(label,
              style: GoogleFonts.inter(
                  fontSize: 13,
                  fontWeight: FontWeight.w500,
                  color: color ?? AppColors.textPrimary)),
        ],
      ),
    );
  }
}
