import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/models/class_session_model.dart';
import '../../../shared/widgets/iron_app_bar.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../../shared/widgets/iron_card.dart';

class ClassDetailScreen extends StatelessWidget {
  final ClassSessionModel session;
  final VoidCallback onReserve;
  const ClassDetailScreen({super.key, required this.session, required this.onReserve});

  @override
  Widget build(BuildContext context) {
    final h = session.dateTime.hour;
    final m = session.dateTime.minute.toString().padLeft(2, '0');
    final ampm = h >= 12 ? 'PM' : 'AM';
    final hour = h > 12 ? h - 12 : (h == 0 ? 12 : h);

    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: IronAppBar(title: session.name),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Hero
            Container(
              width: double.infinity,
              height: 160,
              decoration: BoxDecoration(color: AppColors.dark, borderRadius: BorderRadius.circular(20)),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Icon(Icons.sports_gymnastics_rounded, size: 56, color: AppColors.primary),
                  const Gap(8),
                  Text(session.type, style: GoogleFonts.lexend(fontSize: 16, fontWeight: FontWeight.w700, color: AppColors.onDark)),
                ],
              ),
            ).animate().fadeIn(),
            const Gap(20),

            // Info grid
            GridView.count(
              crossAxisCount: 2,
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              crossAxisSpacing: 12,
              mainAxisSpacing: 12,
              childAspectRatio: 2.2,
              children: [
                _infoCard(Icons.person_outline_rounded, 'Instructor', session.instructor),
                _infoCard(Icons.access_time_rounded, 'Hora', '$hour:$m $ampm'),
                _infoCard(Icons.timer_outlined, 'Duración', '${session.durationMinutes} min'),
                _infoCard(Icons.people_outline_rounded, 'Cupos', '${session.availableSpots} disponibles'),
              ],
            ).animate().fadeIn(delay: 100.ms),
            const Gap(20),

            IronCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Descripción', style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                  const Gap(8),
                  Text(
                    'Clase de ${session.name} de ${session.durationMinutes} minutos de alta intensidad con el entrenador ${session.instructor}. Ideal para todos los niveles.',
                    style: GoogleFonts.inter(fontSize: 13, height: 1.6, color: AppColors.textSecondary),
                  ),
                ],
              ),
            ).animate().fadeIn(delay: 150.ms),
            const Gap(24),

            IronButton(
              label: session.isReserved ? 'CANCELAR RESERVA' : 'RESERVAR CLASE',
              isPrimary: !session.isReserved,
              onPressed: () {
                onReserve();
                Navigator.pop(context);
              },
            ).animate().fadeIn(delay: 200.ms),
          ],
        ),
      ),
    );
  }

  Widget _infoCard(IconData icon, String label, String value) => IronCard(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            Icon(icon, size: 18, color: AppColors.primary),
            const Gap(8),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(label, style: GoogleFonts.inter(fontSize: 10, color: AppColors.textDisabled)),
                  Text(value, style: GoogleFonts.lexend(fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                ],
              ),
            ),
          ],
        ),
      );
}
