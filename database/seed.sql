INSERT INTO categories (name) VALUES ('Mathematics'), ('Physics'), ('Biology');

INSERT INTO topics (category_id, name)
SELECT id, 'Algebra' FROM categories WHERE name = 'Mathematics';
INSERT INTO topics (category_id, name)
SELECT id, 'Geometry' FROM categories WHERE name = 'Mathematics';
INSERT INTO topics (category_id, name)
SELECT id, 'Mechanics' FROM categories WHERE name = 'Physics';
INSERT INTO topics (category_id, name)
SELECT id, 'Cell Biology' FROM categories WHERE name = 'Biology';

INSERT INTO questions (category_id, topic_id, question_text, question_type, explanation, is_demo)
SELECT c.id, t.id, 'What is the value of 2x + 3 when x = 4?', 'single', 'Substitute x = 4, so 2(4) + 3 = 11.', 1
FROM categories c JOIN topics t ON t.category_id = c.id AND t.name = 'Algebra'
WHERE c.name = 'Mathematics';

INSERT INTO question_options (question_id, option_text, is_correct, sort_order)
SELECT q.id, '9', 0, 1 FROM questions q WHERE q.question_text LIKE 'What is the value of 2x%'
UNION ALL SELECT q.id, '11', 1, 2 FROM questions q WHERE q.question_text LIKE 'What is the value of 2x%'
UNION ALL SELECT q.id, '14', 0, 3 FROM questions q WHERE q.question_text LIKE 'What is the value of 2x%'
UNION ALL SELECT q.id, '16', 0, 4 FROM questions q WHERE q.question_text LIKE 'What is the value of 2x%';

INSERT INTO questions (category_id, topic_id, question_text, question_type, explanation, is_demo)
SELECT c.id, t.id, 'Which quantity is measured in Newtons?', 'single', 'Newton is the SI unit of force.', 1
FROM categories c JOIN topics t ON t.category_id = c.id AND t.name = 'Mechanics'
WHERE c.name = 'Physics';

INSERT INTO question_options (question_id, option_text, is_correct, sort_order)
SELECT q.id, 'Force', 1, 1 FROM questions q WHERE q.question_text = 'Which quantity is measured in Newtons?'
UNION ALL SELECT q.id, 'Energy', 0, 2 FROM questions q WHERE q.question_text = 'Which quantity is measured in Newtons?'
UNION ALL SELECT q.id, 'Power', 0, 3 FROM questions q WHERE q.question_text = 'Which quantity is measured in Newtons?';
