import 'package:flutter/material.dart';
import 'features/onboarding/app_tour.dart';
import 'shared/widgets/iron_bottom_nav.dart';
import 'shared/widgets/iron_floating_ai_button.dart';
import 'features/home/screens/home_screen.dart';
import 'features/workouts/screens/workouts_screen.dart';
import 'features/progress/screens/progress_screen.dart';
import 'features/classes/screens/classes_screen.dart';
import 'features/profile/screens/profile_screen.dart';

class AppShell extends StatefulWidget {
  const AppShell({super.key});

  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  int _currentIndex = 0;

  final _screens = const [
    HomeScreen(),
    WorkoutsScreen(),
    ProgressScreen(),
    ClassesScreen(),
    ProfileScreen(),
  ];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _maybeShowTour());
  }

  Future<void> _maybeShowTour() async {
    if (!mounted) return;
    if (await AppTour.shouldShow()) {
      if (!mounted) return;
      AppTour.show(context);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(
        index: _currentIndex,
        children: _screens,
      ),
      floatingActionButton: const IronFloatingAiButton(),
      floatingActionButtonLocation: FloatingActionButtonLocation.centerFloat,
      bottomNavigationBar: IronBottomNav(
        currentIndex: _currentIndex,
        onTap: (i) => setState(() => _currentIndex = i),
      ),
    );
  }
}
