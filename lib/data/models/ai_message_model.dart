class AiMessage {
  final String id;
  final String content;
  final bool isUser;
  final DateTime timestamp;

  AiMessage({
    required this.id,
    required this.content,
    required this.isUser,
    DateTime? timestamp,
  }) : timestamp = timestamp ?? DateTime.now();
}
