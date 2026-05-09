import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/class_session_model.dart';
import '../../../shared/widgets/iron_card.dart';
import '../../../shared/widgets/status_badge.dart';
import 'class_detail_screen.dart';

class ClassesScreen extends StatefulWidget {
  const ClassesScreen({super.key});

  @override
  State<ClassesScreen> createState() => _ClassesScreenState();
}

class _ClassesScreenState extends State<ClassesScreen> {
  String _filter = 'Todas';
  final _filters = ['Todas', 'Cardio', 'Fuerza', 'CrossFit', 'Core', 'Flexibilidad'];
  late List<ClassSessionModel> _classes;

  @override
  void initState() {
    super.initState();
    _classes = mockClasses;
  }

  List<ClassSessionModel> get _filtered =>
      _filter == 'Todas' ? _classes : _classes.where((c) => c.type == _filter).toList();

  void _reserve(ClassSessionModel session) {
    setState(() {
      session.isReserved = !session.isReserved;
      if (session.isReserved) {
        session.bookedSpots++;
        session.status = ClassStatus.reserved;
      } else {
        session.bookedSpots--;
        session.status = ClassSessionModel.computeStatus(session.bookedSpots, session.totalSpots);
      }
    });
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(session.isReserved ? 'Clase reservada' : 'Reserva cancelada'),
      backgroundColor: session.isReserved ? const Color(0xFF155724) : AppColors.textSecondary,
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.surface0,
      body: CustomScrollView(
        slivers: [
          SliverAppBar(
            backgroundColor: AppColors.surface0,
            elevation: 0,
            pinned: true,
            title: Text('Clases', style: GoogleFonts.lexend(fontSize: 20, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
          ),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 0),
              child: SizedBox(
                height: 36,
                child: ListView.separated(
                  scrollDirection: Axis.horizontal,
                  itemCount: _filters.length,
                  separatorBuilder: (_, __) => const Gap(8),
                  itemBuilder: (_, i) {
                    final f = _filters[i];
                    final active = f == _filter;
                    return GestureDetector(
                      onTap: () => setState(() => _filter = f),
                      child: AnimatedContainer(
                        duration: 200.ms,
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
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
            ),
          ),
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 120),
            sliver: SliverList(
              delegate: SliverChildBuilderDelegate(
                (_, i) => Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: _ClassCard(
                    session: _filtered[i],
                    onReserve: () => _reserve(_filtered[i]),
                  ).animate().fadeIn(delay: (i * 80).ms).slideY(begin: 0.1),
                ),
                childCount: _filtered.length,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ClassCard extends StatelessWidget {
  final ClassSessionModel session;
  final VoidCallback onReserve;
  const _ClassCard({required this.session, required this.onReserve});

  (String, BadgeVariant) get _statusInfo => switch (session.status) {
    ClassStatus.available  => ('Disponible', BadgeVariant.success),
    ClassStatus.fewSpots   => ('Pocos cupos', BadgeVariant.warning),
    ClassStatus.waitlist   => ('Lista de espera', BadgeVariant.info),
    ClassStatus.reserved   => ('Reservada', BadgeVariant.success),
    ClassStatus.full       => ('Lleno', BadgeVariant.error),
  };

  @override
  Widget build(BuildContext context) {
    final (statusLabel, variant) = _statusInfo;
    final h = session.dateTime.hour;
    final m = session.dateTime.minute.toString().padLeft(2, '0');
    final ampm = h >= 12 ? 'PM' : 'AM';
    final hour = h > 12 ? h - 12 : (h == 0 ? 12 : h);
    final timeStr = '$hour:$m $ampm';

    return IronCard(
      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => ClassDetailScreen(session: session, onReserve: onReserve))),
      backgroundImage: AppAssets.backgroundClases,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Expanded(
                child: Text(session.name, style: GoogleFonts.lexend(fontSize: 16, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
              ),
              StatusBadge(label: statusLabel, variant: variant),
            ],
          ),
          const Gap(8),
          Row(children: [
            _info(Icons.person_outline_rounded, session.instructor),
            const Gap(16),
            _info(Icons.access_time_rounded, timeStr),
            const Gap(16),
            _info(Icons.timer_outlined, '${session.durationMinutes} min'),
          ]),
          const Gap(12),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                '${session.availableSpots} cupos disponibles',
                style: GoogleFonts.inter(fontSize: 12, color: AppColors.textDisabled),
              ),
              GestureDetector(
                onTap: session.status == ClassStatus.full && !session.isReserved ? null : onReserve,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                  decoration: BoxDecoration(
                    color: session.isReserved ? AppColors.surfaceContainerLow : AppColors.dark,
                    borderRadius: BorderRadius.circular(99),
                  ),
                  child: Text(
                    session.isReserved ? 'Cancelar' : 'Reservar',
                    style: GoogleFonts.lexend(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: session.isReserved ? AppColors.textSecondary : AppColors.onDark,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _info(IconData icon, String label) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 13, color: AppColors.textSecondary),
          const Gap(4),
          Text(label, style: GoogleFonts.inter(fontSize: 12, color: AppColors.textSecondary)),
        ],
      );
}
