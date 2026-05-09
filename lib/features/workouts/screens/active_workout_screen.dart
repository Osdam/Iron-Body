import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/models/workout_model.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../../shared/widgets/iron_card.dart';
import 'workout_summary_screen.dart';

class ActiveWorkoutScreen extends StatefulWidget {
  final WorkoutModel workout;
  const ActiveWorkoutScreen({super.key, required this.workout});

  @override
  State<ActiveWorkoutScreen> createState() => _ActiveWorkoutScreenState();
}

class _ActiveWorkoutScreenState extends State<ActiveWorkoutScreen> {
  int _exerciseIndex = 0;
  late List<List<ActiveSet>> _allSets;
  late Stopwatch _stopwatch;
  late Timer _timer;
  int _restSeconds = 0;
  Timer? _restTimer;
  bool _isResting = false;

  @override
  void initState() {
    super.initState();
    _allSets = widget.workout.exercises.map((ex) =>
        List.generate(ex.sets, (i) => ActiveSet(reps: int.tryParse(ex.reps) ?? 10, weight: ex.weight))).toList();
    _stopwatch = Stopwatch()..start();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) => setState(() {}));
  }

  @override
  void dispose() {
    _timer.cancel();
    _restTimer?.cancel();
    _stopwatch.stop();
    super.dispose();
  }

  String get _elapsed {
    final s = _stopwatch.elapsed;
    return '${s.inMinutes.toString().padLeft(2, '0')}:${(s.inSeconds % 60).toString().padLeft(2, '0')}';
  }

  void _startRest(int seconds) {
    setState(() { _isResting = true; _restSeconds = seconds; });
    _restTimer?.cancel();
    _restTimer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (_restSeconds <= 0) { t.cancel(); setState(() => _isResting = false); }
      else setState(() => _restSeconds--);
    });
  }

  void _finish() {
    _stopwatch.stop();
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(builder: (_) => WorkoutSummaryScreen(workout: widget.workout, elapsed: _stopwatch.elapsed)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final exercise = widget.workout.exercises[_exerciseIndex];
    final sets = _allSets[_exerciseIndex];
    final isLast = _exerciseIndex == widget.workout.exercises.length - 1;

    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: AppBar(
        backgroundColor: AppColors.surface0,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.close_rounded, color: AppColors.textPrimary),
          onPressed: () => showDialog(
            context: context,
            builder: (_) => AlertDialog(
              title: Text('¿Salir del entrenamiento?', style: GoogleFonts.lexend(fontWeight: FontWeight.w700)),
              content: Text('Perderás el progreso actual.', style: GoogleFonts.inter()),
              actions: [
                TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancelar')),
                TextButton(
                  onPressed: () { Navigator.pop(context); Navigator.pop(context); },
                  child: const Text('Salir', style: TextStyle(color: AppColors.error)),
                ),
              ],
            ),
          ),
        ),
        title: Column(
          children: [
            Text(widget.workout.name, style: GoogleFonts.lexend(fontSize: 15, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
            Text(_elapsed, style: GoogleFonts.inter(fontSize: 12, color: AppColors.textSecondary)),
          ],
        ),
        centerTitle: true,
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: 16),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(color: AppColors.primary.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(99)),
              child: Text(
                '${_exerciseIndex + 1}/${widget.workout.exercises.length}',
                style: GoogleFonts.lexend(fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.primary),
              ),
            ),
          ),
        ],
      ),
      body: Column(
        children: [
          // Descanso overlay
          if (_isResting)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              color: AppColors.dark,
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Icon(Icons.timer_outlined, color: AppColors.primary, size: 18),
                  const Gap(8),
                  Text('Descanso: $_restSeconds s', style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.primary)),
                  const Spacer(),
                  TextButton(
                    onPressed: () { _restTimer?.cancel(); setState(() => _isResting = false); },
                    child: Text('Saltar', style: GoogleFonts.inter(fontSize: 12, color: AppColors.onDark.withValues(alpha: 0.6))),
                  ),
                ],
              ),
            ),

          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Ejercicio actual
                  IronCard(
                    color: AppColors.dark,
                    backgroundImage: AppAssets.backgroundEjercicioActual,
                    backgroundImageOpacity: 0.12,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Ejercicio actual', style: GoogleFonts.inter(fontSize: 12, color: AppColors.onDark.withValues(alpha: 0.5))),
                        const Gap(4),
                        Text(exercise.exercise.name, style: GoogleFonts.lexend(fontSize: 22, fontWeight: FontWeight.w700, color: AppColors.onDark)),
                        const Gap(4),
                        Text('${exercise.exercise.muscleGroup} · ${exercise.exercise.equipment}', style: GoogleFonts.inter(fontSize: 13, color: AppColors.onDark.withValues(alpha: 0.6))),
                        const Gap(12),
                        Row(children: [
                          _statChip('Series', '${exercise.sets}'),
                          const Gap(12),
                          _statChip('Reps', exercise.reps),
                          const Gap(12),
                          _statChip('Peso', '${exercise.weight} kg'),
                        ]),
                      ],
                    ),
                  ).animate().fadeIn(),

                  const Gap(20),
                  Text('Series completadas', style: GoogleFonts.lexend(fontSize: 16, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                  const Gap(10),

                  // Tabla de series
                  IronCard(
                    padding: EdgeInsets.zero,
                    child: Column(
                      children: [
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                          child: Row(children: [
                            _header('Serie', flex: 1),
                            _header('Reps', flex: 2),
                            _header('Peso (kg)', flex: 2),
                            _header('RPE', flex: 1),
                            const SizedBox(width: 40),
                          ]),
                        ),
                        const Divider(height: 1, color: AppColors.border),
                        ...sets.asMap().entries.map((entry) {
                          final i = entry.key;
                          final set = entry.value;
                          return _SetRow(
                            index: i + 1,
                            activeSet: set,
                            onComplete: () {
                              setState(() => set.completed = !set.completed);
                              if (set.completed) _startRest(90);
                            },
                          );
                        }),
                        Padding(
                          padding: const EdgeInsets.all(12),
                          child: GestureDetector(
                            onTap: () => setState(() => sets.add(ActiveSet(reps: exercise.reps.isEmpty ? 10 : (int.tryParse(exercise.reps) ?? 10), weight: exercise.weight))),
                            child: Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                const Icon(Icons.add_circle_outline_rounded, size: 18, color: AppColors.textSecondary),
                                const Gap(6),
                                Text('Agregar serie', style: GoogleFonts.inter(fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.textSecondary)),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
                  ).animate().fadeIn(delay: 100.ms),

                  const Gap(24),
                  Row(children: [
                    if (!isLast)
                      Expanded(
                        child: IronButton(
                          label: 'SIGUIENTE',
                          onPressed: () => setState(() => _exerciseIndex++),
                        ),
                      ),
                    if (!isLast) const Gap(12),
                    Expanded(
                      child: IronButton(
                        label: 'FINALIZAR',
                        isPrimary: isLast,
                        onPressed: _finish,
                      ),
                    ),
                  ]),
                  const Gap(16),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _statChip(String label, String value) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: GoogleFonts.inter(fontSize: 10, color: AppColors.onDark.withValues(alpha: 0.5))),
          Text(value, style: GoogleFonts.lexend(fontSize: 16, fontWeight: FontWeight.w700, color: AppColors.primary)),
        ],
      );

  Widget _header(String label, {int flex = 1}) => Expanded(
        flex: flex,
        child: Text(label, style: GoogleFonts.lexend(fontSize: 11, fontWeight: FontWeight.w700, color: AppColors.textSecondary)),
      );
}

class _SetRow extends StatefulWidget {
  final int index;
  final ActiveSet activeSet;
  final VoidCallback onComplete;
  const _SetRow({required this.index, required this.activeSet, required this.onComplete});

  @override
  State<_SetRow> createState() => _SetRowState();
}

class _SetRowState extends State<_SetRow> {
  late TextEditingController _repsCtrl;
  late TextEditingController _weightCtrl;

  @override
  void initState() {
    super.initState();
    _repsCtrl = TextEditingController(text: '${widget.activeSet.reps}');
    _weightCtrl = TextEditingController(text: '${widget.activeSet.weight}');
  }

  @override
  void dispose() {
    _repsCtrl.dispose();
    _weightCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final done = widget.activeSet.completed;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      decoration: BoxDecoration(
        color: done ? AppColors.primary.withValues(alpha: 0.07) : Colors.transparent,
      ),
      child: Row(
        children: [
          Expanded(
            flex: 1,
            child: Text(
              '${widget.index}',
              style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.textPrimary),
            ),
          ),
          Expanded(
            flex: 2,
            child: _miniInput(_repsCtrl, (v) => widget.activeSet.reps = int.tryParse(v) ?? widget.activeSet.reps),
          ),
          const Gap(8),
          Expanded(
            flex: 2,
            child: _miniInput(_weightCtrl, (v) => widget.activeSet.weight = double.tryParse(v) ?? widget.activeSet.weight),
          ),
          const Gap(8),
          Expanded(
            flex: 1,
            child: Text('${widget.activeSet.rpe}', style: GoogleFonts.inter(fontSize: 13, color: AppColors.textSecondary), textAlign: TextAlign.center),
          ),
          GestureDetector(
            onTap: widget.onComplete,
            child: Container(
              width: 32,
              height: 32,
              decoration: BoxDecoration(
                color: done ? AppColors.primary : AppColors.surfaceContainerLow,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Icon(done ? Icons.check_rounded : Icons.radio_button_unchecked_rounded, size: 18, color: done ? AppColors.dark : AppColors.textDisabled),
            ),
          ),
        ],
      ),
    );
  }

  Widget _miniInput(TextEditingController ctrl, ValueChanged<String> onChanged) => SizedBox(
        height: 36,
        child: TextField(
          controller: ctrl,
          onChanged: onChanged,
          keyboardType: TextInputType.number,
          textAlign: TextAlign.center,
          style: GoogleFonts.inter(fontSize: 14, fontWeight: FontWeight.w600, color: AppColors.textPrimary),
          decoration: InputDecoration(
            contentPadding: const EdgeInsets.symmetric(horizontal: 8),
            filled: true,
            fillColor: AppColors.surfaceContainerLow,
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: BorderSide.none),
            focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: const BorderSide(color: AppColors.primary)),
          ),
        ),
      );
}
