import 'package:flutter/material.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../../core/theme/app_colors.dart';
import '../services/iron_ai_service.dart';

/// Componente reutilizable que muestra el estado de acceso a IRON IA.
///
/// Todos los textos/límites provienen del backend (IronAiAccess); aquí NO se
/// hardcodean cuotas. Diseño Iron Body: blanco / negro / amarillo, premium.
///
/// Estados (IronAiBannerState):
///  - freeTrialAvailable / membershipAvailable → tira compacta con contador.
///  - freeTrialExhausted / membershipQuotaExhausted / premiumLocked → tarjeta
///    premium con CTA para comprar/mejorar membresía.
///
/// [compact] true = tira superior; false = tarjeta de bloqueo (sobre el input).
class IronAiAccessBanner extends StatelessWidget {
  final IronAiAccess access;
  final bool compact;
  final VoidCallback onSeeMemberships;
  final VoidCallback? onDismiss;

  const IronAiAccessBanner({
    super.key,
    required this.access,
    required this.onSeeMemberships,
    this.compact = true,
    this.onDismiss,
  });

  bool get _blocked => !access.canUseChat;

  @override
  Widget build(BuildContext context) {
    return compact ? _buildStrip() : _buildCard();
  }

  // ── Tira compacta superior ─────────────────────────────────────────────────
  Widget _buildStrip() {
    final blocked = _blocked;
    final text = _stripText();
    final bg = blocked
        ? AppColors.dark
        : AppColors.primary.withValues(alpha: 0.12);
    final fg = blocked ? AppColors.primary : AppColors.textPrimary;

    return GestureDetector(
      onTap: blocked ? onSeeMemberships : null,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
        decoration: BoxDecoration(
          color: bg,
          border: const Border(bottom: BorderSide(color: AppColors.border)),
        ),
        child: Row(
          children: [
            Icon(
              blocked ? Icons.lock_rounded : Icons.bolt_rounded,
              size: 16,
              color: blocked ? AppColors.primary : AppColors.primary,
            ),
            const Gap(8),
            Expanded(
              child: Text(
                text,
                style: GoogleFonts.inter(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: fg,
                ),
              ),
            ),
            if (blocked)
              Text(
                'Ver membresías',
                style: GoogleFonts.lexend(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: AppColors.primary,
                ),
              ),
          ],
        ),
      ),
    );
  }

  String _stripText() {
    switch (access.bannerState) {
      case IronAiBannerState.freeTrialAvailable:
        final limit = access.messageLimit ?? 5;
        final remaining = access.remainingMessages ??
            (limit - access.usedMessages).clamp(0, limit);
        return 'Prueba gratuita: $remaining de $limit consultas disponibles';
      case IronAiBannerState.membershipAvailable:
        if (access.remainingMonth != null) {
          return 'IRON IA: ${access.remainingMonth} consultas disponibles este mes';
        }
        return access.planName != null
            ? 'IRON IA activo · Plan ${access.planName}'
            : 'IRON IA activo';
      case IronAiBannerState.freeTrialExhausted:
        return 'Agotaste tu prueba gratuita de IRON IA';
      case IronAiBannerState.membershipQuotaExhausted:
        return 'Alcanzaste el límite de IRON IA de tu membresía';
      case IronAiBannerState.premiumLocked:
        return 'IRON IA no está disponible en tu plan actual';
    }
  }

  // ── Tarjeta de bloqueo (upsell) ────────────────────────────────────────────
  Widget _buildCard() {
    final state = access.bannerState;
    final isMembership = state == IronAiBannerState.membershipQuotaExhausted;

    final title = switch (state) {
      IronAiBannerState.membershipQuotaExhausted => 'Límite alcanzado',
      IronAiBannerState.premiumLocked => 'IRON IA bloqueado',
      _ => 'Desbloquea IRON IA',
    };

    final body = switch (state) {
      IronAiBannerState.membershipQuotaExhausted =>
        'Has alcanzado el límite de IRON IA de tu membresía. Mejora tu plan para seguir recibiendo asistencia personalizada.',
      IronAiBannerState.premiumLocked =>
        'IRON IA no está disponible en tu plan actual. Adquiere una membresía para usar el asistente.',
      _ =>
        'Ya usaste tus ${access.messageLimit ?? 5} consultas gratuitas. Compra una membresía para seguir recibiendo asistencia personalizada para tus rutinas, técnica y progreso.',
    };

    final primaryLabel = access.cta?.title ??
        (isMembership ? 'Mejorar plan' : 'Ver membresías');
    final secondaryLabel = isMembership ? 'Ver membresías' : 'Más tarde';

    return Container(
      width: double.infinity,
      margin: const EdgeInsets.fromLTRB(16, 8, 16, 16),
      padding: const EdgeInsets.fromLTRB(18, 18, 18, 16),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.primary.withValues(alpha: 0.45)),
        boxShadow: [
          BoxShadow(
            color: AppColors.primary.withValues(alpha: 0.12),
            blurRadius: 18,
            spreadRadius: 1,
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: AppColors.primary.withValues(alpha: 0.16),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(Icons.workspace_premium_rounded,
                    color: AppColors.primary, size: 22),
              ),
              const Gap(12),
              Expanded(
                child: Text(
                  title,
                  style: GoogleFonts.lexend(
                    fontSize: 17,
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                  ),
                ),
              ),
            ],
          ),
          const Gap(12),
          Text(
            body,
            style: GoogleFonts.inter(
              fontSize: 13,
              height: 1.5,
              color: AppColors.textSecondary,
            ),
          ),
          const Gap(16),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(
              onPressed: onSeeMemberships,
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primary,
                foregroundColor: AppColors.dark,
                elevation: 0,
                padding: const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(14),
                ),
              ),
              child: Text(
                primaryLabel,
                style: GoogleFonts.lexend(
                  fontSize: 14,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
          ),
          const Gap(8),
          SizedBox(
            width: double.infinity,
            child: TextButton(
              onPressed: isMembership ? onSeeMemberships : onDismiss,
              child: Text(
                secondaryLabel,
                style: GoogleFonts.inter(
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textSecondary,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
