import 'package:flutter/material.dart';

class TrainerModel {
  final String id;
  final String name;
  final String specialty;
  final String bio;
  final int experienceYears;
  final int studentCount;
  double averageRating;
  int ratingCount;
  bool isFavorite;
  final Color bannerColor1;
  final Color bannerColor2;
  final List<TrainerReview> reviews;

  TrainerModel({
    required this.id,
    required this.name,
    required this.specialty,
    required this.bio,
    required this.experienceYears,
    required this.studentCount,
    required this.averageRating,
    required this.ratingCount,
    this.isFavorite = false,
    required this.bannerColor1,
    required this.bannerColor2,
    List<TrainerReview>? reviews,
  }) : reviews = reviews ?? [];

  String get initials => name
      .split(' ')
      .take(2)
      .map((w) => w.isEmpty ? '' : w[0].toUpperCase())
      .join();
}

class TrainerReview {
  final String userName;
  final double rating;
  final String comment;
  final DateTime date;

  const TrainerReview({
    required this.userName,
    required this.rating,
    required this.comment,
    required this.date,
  });
}
