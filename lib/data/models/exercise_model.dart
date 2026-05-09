class ExerciseModel {
  final String id;
  final String name;
  final String muscleGroup;
  final String equipment;
  final String difficulty;
  final String description;
  final List<String> steps;
  final List<String> commonMistakes;
  final List<String> secondaryMuscles;
  final int suggestedSets;
  final String suggestedReps;

  const ExerciseModel({
    required this.id,
    required this.name,
    required this.muscleGroup,
    required this.equipment,
    required this.difficulty,
    required this.description,
    required this.steps,
    this.commonMistakes = const [],
    this.secondaryMuscles = const [],
    this.suggestedSets = 3,
    this.suggestedReps = '8-12',
  });
}
