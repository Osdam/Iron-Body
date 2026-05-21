import 'package:flutter/material.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/mock/mock_data.dart';
import '../../../shared/widgets/lottie_quick_action_card.dart';
import '../../memberships/screens/memberships_screen.dart';
import '../../workouts/screens/workouts_screen.dart';
import '../../workouts/screens/active_workout_screen.dart';
import '../../classes/screens/classes_screen.dart';
import '../../progress/screens/progress_screen.dart';
import '../../notifications/screens/notifications_screen.dart';
import '../../iron_ai/screens/iron_ai_home_screen.dart';
import '../../store/screens/store_screen.dart';
import '../../profile/screens/profile_screen.dart';
import '../../nutrition/widgets/nutrition_home_card.dart';
import '../widgets/animated_gym_background.dart';
import '../widgets/premium_header.dart';
import '../widgets/home_cards.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final user = AppSession.currentUser!;
    final reservedClass = mockClasses.firstWhere(
      (c) => c.isReserved,
      orElse: () => mockClasses[1],
    );
    final todayWorkout = mockWorkouts.firstWhere(
      (w) => w.isAssigned,
      orElse: () => mockWorkouts[0],
    );

    return Scaffold(
      backgroundColor: AppColors.surface0,
      body: AnimatedGymBackground(
        child: SafeArea(
          child: CustomScrollView(
            slivers: [
              SliverToBoxAdapter(
                child: PremiumHeader(
                  user: user,
                  unreadNotifications: 3,
                  onNotificationTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => const NotificationsScreen(),
                    ),
                  ),
                  onAvatarTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => const ProfileScreen()),
                  ),
                ),
              ),
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 120),
                sliver: SliverList(
                  delegate: SliverChildListDelegate([
                    MembershipHeroCard(
                      user: user,
                      onRenew: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => const MembershipsScreen(),
                        ),
                      ),
                    ),
                    const Gap(20),

                    WorkoutTodayCard(
                      workout: todayWorkout,
                      onStart: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) =>
                              ActiveWorkoutScreen(workout: todayWorkout),
                        ),
                      ),
                    ),
                    const Gap(20),

                    // Section header
                    Text(
                      'Accesos rápidos',
                      style: GoogleFonts.lexend(
                        fontSize: 16,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    const Gap(12),

                    // Lottie quick actions grid
                    GridView.builder(
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      gridDelegate:
                          const SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: 3,
                        crossAxisSpacing: 12,
                        mainAxisSpacing: 12,
                        childAspectRatio: 0.88,
                      ),
                      itemCount: _quickActions(context).length,
                      itemBuilder: (_, i) {
                        final a = _quickActions(context)[i];
                        return LottieQuickActionCard(
                          lottiePath: a.$1,
                          label: a.$2,
                          onTap: a.$3,
                        );
                      },
                    ),
                    const Gap(16),

                    // Resumen de nutrición del día
                    const NutritionHomeCard(),
                    const Gap(24),

                    NextClassCard(
                      session: reservedClass,
                      onViewAll: () => Navigator.push(
                        context,
                        MaterialPageRoute(builder: (_) => const ClassesScreen()),
                      ),
                    ),
                    const Gap(24),

                    WeeklySummaryCard(
                      completed: 3,
                      goal: 5,
                      streak: user.streak,
                    ),
                    const Gap(24),

                    IronAiPromoCard(
                      onTap: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => const IronAiHomeScreen(),
                        ),
                      ),
                    ),
                  ]),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  List<(String, String, VoidCallback)> _quickActions(BuildContext context) => [
        (
          AppAssets.lottieRutina,
          'Mi rutina',
          () => Navigator.push(
            context,
            MaterialPageRoute(builder: (_) => const WorkoutsScreen()),
          ),
        ),
        (
          AppAssets.lottieAgenda,
          'Clases',
          () => Navigator.push(
            context,
            MaterialPageRoute(builder: (_) => const ClassesScreen()),
          ),
        ),
        (
          AppAssets.lottieMembresias,
          'Membresía',
          () => Navigator.push(
            context,
            MaterialPageRoute(builder: (_) => const MembershipsScreen()),
          ),
        ),
        (
          AppAssets.lottieProgreso,
          'Progreso',
          () => Navigator.push(
            context,
            MaterialPageRoute(builder: (_) => const ProgressScreen()),
          ),
        ),
        (
          AppAssets.ironAi,
          'IRON IA',
          () => Navigator.push(
            context,
            MaterialPageRoute(builder: (_) => const IronAiHomeScreen()),
          ),
        ),
        (
          AppAssets.lottieTienda,
          'Tienda',
          () => Navigator.push(
            context,
            MaterialPageRoute(builder: (_) => const StoreScreen()),
          ),
        ),
      ];
}
