import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/user_model.dart';
import '../../../shared/widgets/app_lottie_icon.dart';
import '../../../shared/widgets/iron_card.dart';
import '../../../shared/widgets/iron_input.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../auth/screens/login_screen.dart';
import '../../memberships/screens/memberships_screen.dart';
import '../../onboarding/app_tour.dart';
import '../../payments/screens/payment_history_screen.dart';
import '../../progress/screens/physical_evaluation_screen.dart';
import '../../store/screens/store_screen.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final user = AppSession.currentUser!;

    return Scaffold(
      backgroundColor: AppColors.surface0,
      body: CustomScrollView(
        slivers: [
          SliverAppBar(
            backgroundColor: AppColors.surface0,
            elevation: 0,
            title: Text(
              'Perfil',
              style: GoogleFonts.lexend(
                fontSize: 20,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
              ),
            ),
            actions: [
              IconButton(
                onPressed: () => _showEditSheet(context, user),
                icon: Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(
                    color: AppColors.surfaceContainerLow,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: AppColors.border),
                  ),
                  child: AppLottieIcon(path: AppAssets.lottieEditUser, size: 18),
                ),
              ),
              const SizedBox(width: 8),
            ],
          ),
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 120),
            sliver: SliverList(
              delegate: SliverChildListDelegate([
                _ProfileHeader(user: user).animate().fadeIn(),
                const Gap(20),

                Row(
                  children: [
                    Expanded(
                      child: _statCard('${user.workoutsCompleted}', 'Entrenos'),
                    ),
                    const Gap(12),
                    Expanded(
                      child: _statCard('${user.streak}', 'Racha días'),
                    ),
                    const Gap(12),
                    Expanded(
                      child:
                          _statCard('${user.daysRemaining}', 'Días membresía'),
                    ),
                  ],
                ).animate().fadeIn(delay: 100.ms),
                const Gap(24),

                _PlanCard(user: user).animate().fadeIn(delay: 150.ms),
                const Gap(24),

                _MenuItem(
                  lottiePath: AppAssets.lottieGym,
                  label: 'Evaluación física',
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => const PhysicalEvaluationScreen(),
                    ),
                  ),
                ).animate().fadeIn(delay: 200.ms),
                const Gap(8),
                _MenuItem(
                  lottiePath: AppAssets.lottieMembresias,
                  label: 'Membresías y pagos',
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => const MembershipsScreen(),
                    ),
                  ),
                ).animate().fadeIn(delay: 230.ms),
                const Gap(8),
                _MenuItem(
                  lottiePath: AppAssets.lottieMembresias,
                  label: 'Historial de pagos',
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => const PaymentHistoryScreen(),
                    ),
                  ),
                ).animate().fadeIn(delay: 245.ms),
                const Gap(8),
                _MenuItem(
                  lottiePath: AppAssets.lottieTienda,
                  label: 'Tienda',
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => const StoreScreen()),
                  ),
                ).animate().fadeIn(delay: 260.ms),
                const Gap(8),
                _MenuItem(
                  lottiePath: AppAssets.lottiePasseword,
                  label: 'Cambiar contraseña',
                  onTap: () => _showChangePasswordSheet(context),
                ).animate().fadeIn(delay: 290.ms),
                const Gap(8),
                _MenuItem(
                  lottiePath: AppAssets.lottieNotificaciones,
                  label: 'Preferencias de notificación',
                  onTap: () {},
                ).animate().fadeIn(delay: 320.ms),
                const Gap(8),
                _MenuItem(
                  lottiePath: AppAssets.lottieVerGuiaApp,
                  label: 'Ver guía de la app',
                  onTap: () async {
                    await AppTour.reset();
                    if (context.mounted) AppTour.show(context);
                  },
                ).animate().fadeIn(delay: 350.ms),
                const Gap(8),
                _MenuItem(
                  lottiePath: AppAssets.lottieInforamcion,
                  label: 'Acerca de Iron Body',
                  onTap: () {},
                ).animate().fadeIn(delay: 380.ms),
                const Gap(20),

                IronCard(
                  onTap: () {
                    AppSession.logout();
                    Navigator.pushAndRemoveUntil(
                      context,
                      MaterialPageRoute(builder: (_) => const LoginScreen()),
                      (_) => false,
                    );
                  },
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      AppLottieIcon(path: AppAssets.lottieCerrarSesion, size: 22),
                      const Gap(8),
                      Text(
                        'Cerrar sesión',
                        style: GoogleFonts.lexend(
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                          color: AppColors.error,
                        ),
                      ),
                    ],
                  ),
                ).animate().fadeIn(delay: 420.ms),
              ]),
            ),
          ),
        ],
      ),
    );
  }

  void _showEditSheet(BuildContext context, UserModel user) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _ProfileEditSheet(user: user),
    );
  }

  void _showChangePasswordSheet(BuildContext context) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const _ChangePasswordSheet(),
    );
  }

  Widget _statCard(String value, String label) => IronCard(
        padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 8),
        child: Column(
          children: [
            Text(
              value,
              style: GoogleFonts.lexend(
                fontSize: 22,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
              ),
            ),
            Text(
              label,
              style: GoogleFonts.inter(
                fontSize: 10,
                color: AppColors.textSecondary,
              ),
              textAlign: TextAlign.center,
              maxLines: 2,
            ),
          ],
        ),
      );
}

// ─────────────────────────────────────────────────────────────────────────────
// Profile Header
// ─────────────────────────────────────────────────────────────────────────────

class _ProfileHeader extends StatelessWidget {
  final UserModel user;
  const _ProfileHeader({required this.user});

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Stack(
          children: [
            Container(
              width: 76,
              height: 76,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: AppColors.primary.withValues(alpha: 0.15),
                border: Border.all(color: AppColors.primary, width: 2),
              ),
              child: Center(
                child: Text(
                  user.firstName.substring(0, 1).toUpperCase(),
                  style: GoogleFonts.lexend(
                    fontSize: 32,
                    fontWeight: FontWeight.w700,
                    color: AppColors.primary,
                  ),
                ),
              ),
            ),
          ],
        ),
        const Gap(16),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                user.fullName,
                style: GoogleFonts.lexend(
                  fontSize: 18,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              Text(
                user.email,
                style: GoogleFonts.inter(
                  fontSize: 13,
                  color: AppColors.textSecondary,
                ),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              const Gap(4),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: const Color(0xFFD4EDDA),
                  borderRadius: BorderRadius.circular(99),
                ),
                child: Text(
                  user.goal,
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    color: const Color(0xFF155724),
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Plan Card
// ─────────────────────────────────────────────────────────────────────────────

class _PlanCard extends StatelessWidget {
  final UserModel user;
  const _PlanCard({required this.user});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.dark,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.workspace_premium_rounded,
            color: AppColors.primary,
            size: 28,
          ),
          const Gap(12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  user.planName,
                  style: GoogleFonts.lexend(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: AppColors.onDark,
                  ),
                ),
                Text(
                  'Vence en ${user.daysRemaining} días',
                  style: GoogleFonts.inter(
                    fontSize: 12,
                    color: AppColors.onDark.withValues(alpha: 0.6),
                  ),
                ),
              ],
            ),
          ),
          GestureDetector(
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const MembershipsScreen()),
            ),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              decoration: BoxDecoration(
                color: AppColors.primary,
                borderRadius: BorderRadius.circular(99),
              ),
              child: Text(
                'Renovar',
                style: GoogleFonts.lexend(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: AppColors.dark,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Menu Item
// ─────────────────────────────────────────────────────────────────────────────

class _MenuItem extends StatelessWidget {
  final IconData? icon;
  final String? lottiePath;
  final String label;
  final VoidCallback onTap;
  const _MenuItem({
    this.icon,
    this.lottiePath,
    required this.label,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return IronCard(
      onTap: onTap,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      child: Row(
        children: [
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              color: AppColors.surfaceContainerLow,
              borderRadius: BorderRadius.circular(10),
            ),
            child: lottiePath != null
                ? Center(child: AppLottieIcon(path: lottiePath!, size: 22))
                : Icon(icon ?? Icons.circle_outlined, size: 20, color: AppColors.textSecondary),
          ),
          const Gap(14),
          Expanded(
            child: Text(
              label,
              style: GoogleFonts.inter(
                fontSize: 14,
                fontWeight: FontWeight.w500,
                color: AppColors.textPrimary,
              ),
            ),
          ),
          const Icon(
            Icons.chevron_right_rounded,
            color: AppColors.textDisabled,
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Edit Profile Sheet
// ─────────────────────────────────────────────────────────────────────────────

class _ProfileEditSheet extends StatefulWidget {
  final UserModel user;
  const _ProfileEditSheet({required this.user});

  @override
  State<_ProfileEditSheet> createState() => _ProfileEditSheetState();
}

class _ProfileEditSheetState extends State<_ProfileEditSheet> {
  late final TextEditingController _nameCtrl;
  late final TextEditingController _phoneCtrl;
  late final TextEditingController _emailCtrl;
  late final TextEditingController _weightCtrl;
  late final TextEditingController _heightCtrl;
  late String _goal;
  late String _level;
  bool _saving = false;
  bool _saved = false;

  final _goals = [
    'Hipertrofia muscular',
    'Pérdida de grasa',
    'Resistencia',
    'Fuerza',
    'Bienestar general',
  ];
  final _levels = ['Principiante', 'Intermedio', 'Avanzado'];

  @override
  void initState() {
    super.initState();
    _nameCtrl = TextEditingController(text: widget.user.fullName);
    _phoneCtrl = TextEditingController(text: widget.user.phone);
    _emailCtrl = TextEditingController(text: widget.user.email);
    _weightCtrl = TextEditingController(text: widget.user.weight.toString());
    _heightCtrl = TextEditingController(text: widget.user.height.toString());
    _goal = _goals.contains(widget.user.goal) ? widget.user.goal : _goals[0];
    _level = _levels[0];
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _phoneCtrl.dispose();
    _emailCtrl.dispose();
    _weightCtrl.dispose();
    _heightCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _saving = true);
    await Future.delayed(const Duration(milliseconds: 700));
    if (mounted) {
      setState(() {
        _saving = false;
        _saved = true;
      });
      await Future.delayed(const Duration(milliseconds: 900));
      if (mounted) Navigator.pop(context);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.viewInsetsOf(context).bottom;

    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      padding: EdgeInsets.fromLTRB(24, 0, 24, 24 + bottom),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Gap(12),
          Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
              color: AppColors.border,
              borderRadius: BorderRadius.circular(99),
            ),
          ),
          const Gap(20),
          Text(
            'Editar perfil',
            style: GoogleFonts.lexend(
              fontSize: 20,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
          const Gap(24),
          Flexible(
            child: SingleChildScrollView(
              child: Column(
                children: [
                  IronInput(
                    label: 'Nombre completo',
                    hint: 'Tu nombre',
                    controller: _nameCtrl,
                    prefixIcon: Icons.person_outline_rounded,
                  ),
                  const Gap(14),
                  IronInput(
                    label: 'Teléfono',
                    hint: '300 000 0000',
                    controller: _phoneCtrl,
                    prefixIcon: Icons.phone_outlined,
                    keyboardType: TextInputType.phone,
                  ),
                  const Gap(14),
                  IronInput(
                    label: 'Correo electrónico',
                    hint: 'correo@ejemplo.com',
                    controller: _emailCtrl,
                    prefixIcon: Icons.email_outlined,
                    keyboardType: TextInputType.emailAddress,
                  ),
                  const Gap(14),
                  Row(
                    children: [
                      Expanded(
                        child: IronInput(
                          label: 'Peso (kg)',
                          hint: '75',
                          controller: _weightCtrl,
                          keyboardType: TextInputType.number,
                        ),
                      ),
                      const Gap(12),
                      Expanded(
                        child: IronInput(
                          label: 'Estatura (cm)',
                          hint: '175',
                          controller: _heightCtrl,
                          keyboardType: TextInputType.number,
                        ),
                      ),
                    ],
                  ),
                  const Gap(14),
                  _buildDropdown('Objetivo físico', _goal, _goals,
                      (v) => setState(() => _goal = v!)),
                  const Gap(14),
                  _buildDropdown('Nivel de experiencia', _level, _levels,
                      (v) => setState(() => _level = v!)),
                  const Gap(24),
                ],
              ),
            ),
          ),
          if (_saved)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFD4EDDA),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Icon(
                    Icons.check_circle_rounded,
                    color: Color(0xFF155724),
                    size: 18,
                  ),
                  const Gap(8),
                  Text(
                    'Cambios guardados correctamente',
                    style: GoogleFonts.inter(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: const Color(0xFF155724),
                    ),
                  ),
                ],
              ),
            )
          else
            IronButton(
              label: _saving ? 'GUARDANDO...' : 'GUARDAR CAMBIOS',
              onPressed: _saving ? () {} : _save,
            ),
        ],
      ),
    );
  }

  Widget _buildDropdown(
    String label,
    String value,
    List<String> items,
    ValueChanged<String?> onChanged,
  ) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: GoogleFonts.inter(
            fontSize: 13,
            fontWeight: FontWeight.w600,
            color: AppColors.textSecondary,
          ),
        ),
        const Gap(6),
        Container(
          decoration: BoxDecoration(
            color: AppColors.surface1,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: AppColors.border),
          ),
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: DropdownButton<String>(
            value: value,
            isExpanded: true,
            underline: const SizedBox(),
            icon: const Icon(
              Icons.keyboard_arrow_down_rounded,
              color: AppColors.textSecondary,
            ),
            style: GoogleFonts.inter(
              fontSize: 15,
              fontWeight: FontWeight.w500,
              color: AppColors.textPrimary,
            ),
            onChanged: onChanged,
            items: items
                .map((e) => DropdownMenuItem(value: e, child: Text(e)))
                .toList(),
          ),
        ),
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Change Password Sheet
// ─────────────────────────────────────────────────────────────────────────────

class _ChangePasswordSheet extends StatefulWidget {
  const _ChangePasswordSheet();

  @override
  State<_ChangePasswordSheet> createState() => _ChangePasswordSheetState();
}

class _ChangePasswordSheetState extends State<_ChangePasswordSheet> {
  final _currentCtrl = TextEditingController();
  final _newCtrl = TextEditingController();
  final _confirmCtrl = TextEditingController();
  bool _saving = false;

  @override
  void dispose() {
    _currentCtrl.dispose();
    _newCtrl.dispose();
    _confirmCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_newCtrl.text != _confirmCtrl.text) return;
    setState(() => _saving = true);
    await Future.delayed(const Duration(milliseconds: 800));
    if (mounted) Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.viewInsetsOf(context).bottom;

    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      padding: EdgeInsets.fromLTRB(24, 0, 24, 24 + bottom),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Gap(12),
          Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
              color: AppColors.border,
              borderRadius: BorderRadius.circular(99),
            ),
          ),
          const Gap(20),
          Text(
            'Cambiar contraseña',
            style: GoogleFonts.lexend(
              fontSize: 20,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
          const Gap(24),
          IronInput(
            label: 'Contraseña actual',
            hint: '••••••••',
            controller: _currentCtrl,
            isPassword: true,
            prefixIcon: Icons.lock_outline_rounded,
          ),
          const Gap(14),
          IronInput(
            label: 'Nueva contraseña',
            hint: '••••••••',
            controller: _newCtrl,
            isPassword: true,
            prefixIcon: Icons.lock_outline_rounded,
          ),
          const Gap(14),
          IronInput(
            label: 'Confirmar contraseña',
            hint: '••••••••',
            controller: _confirmCtrl,
            isPassword: true,
            prefixIcon: Icons.lock_outline_rounded,
          ),
          const Gap(24),
          IronButton(
            label: _saving ? 'GUARDANDO...' : 'CAMBIAR CONTRASEÑA',
            onPressed: _saving ? () {} : _save,
          ),
        ],
      ),
    );
  }
}
