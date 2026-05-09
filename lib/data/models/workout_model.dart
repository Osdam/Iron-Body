import 'exercise_model.dart';

class WorkoutExercise {
  final ExerciseModel exercise;
  int sets;
  String reps;
  double weight;
  String notes;

  WorkoutExercise({
    required this.exercise,
    this.sets = 3,
    this.reps = '10',
    this.weight = 0,
    this.notes = '',
  });
}

class ActiveSet {
  int reps;
  double weight;
  int rpe;
  bool completed;

  ActiveSet({
    this.reps = 10,
    this.weight = 0,
    this.rpe = 7,
    this.completed = false,
  });
}

class WorkoutModel {
  final String id;
  final String name;
  final String muscleGroup;
  final String level;
  final int estimatedMinutes;
  final List<WorkoutExercise> exercises;
  final bool isAssigned;

  const WorkoutModel({
    required this.id,
    required this.name,
    required this.muscleGroup,
    required this.level,
    required this.estimatedMinutes,
    required this.exercises,
    this.isAssigned = false,
  });

  int get exerciseCount => exercises.length;
}
