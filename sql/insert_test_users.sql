-- Insert test users into the users table
INSERT INTO users (username, email, password_hash, role) VALUES
('admin1', 'admin1@example.com', '$2y$10$e0NRzQ1vQ1vQ1vQ1vQ1vQOq1vQ1vQ1vQ1vQ1vQ1vQ1vQ1vQ1vQ1vQ', 'admin'),
('manager1', 'manager1@example.com', '$2y$10$e0NRzQ1vQ1vQ1vQ1vQ1vQOq1vQ1vQ1vQ1vQ1vQ1vQ1vQ1vQ1vQ1vQ', 'manager'),
('tenant1', 'tenant1@example.com', '$2y$10$e0NRzQ1vQ1vQ1vQ1vQ1vQOq1vQ1vQ1vQ1vQ1vQ1vQ1vQ1vQ1vQ1vQ', 'tenant'),
('tenant2', 'tenant2@example.com', '$2y$10$e0NRzQ1vQ1vQ1vQ1vQ1vQOq1vQ1vQ1vQ1vQ1vQ1vQ1vQ1vQ1vQ1vQ', 'tenant');

-- Note: The password_hash values above are placeholders. Replace with actual hashed passwords.
