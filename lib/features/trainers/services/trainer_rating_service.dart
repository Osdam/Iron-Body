import '../models/trainer_model.dart';

class TrainerRatingService {
  TrainerRatingService._();
  static final instance = TrainerRatingService._();

  Future<bool> submitRating({
    required TrainerModel trainer,
    required double rating,
    required String comment,
  }) async {
    await Future.delayed(const Duration(milliseconds: 900));
    final total = trainer.averageRating * trainer.ratingCount + rating;
    trainer.ratingCount++;
    trainer.averageRating = total / trainer.ratingCount;
    if (comment.trim().isNotEmpty) {
      trainer.reviews.add(TrainerReview(
        userName: 'Tú',
        rating: rating,
        comment: comment.trim(),
        date: DateTime.now(),
      ));
    }
    return true;
  }
}
