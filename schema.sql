CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `email` VARCHAR(100) NOT NULL,
    `role` ENUM('admin', 'client') DEFAULT 'client',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `boxes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `ssh_port` INT DEFAULT 22,
    `ssh_user` VARCHAR(50) NOT NULL,
    `ssh_password` VARCHAR(255) NOT NULL,
    `os_type` VARCHAR(20) DEFAULT 'linux'
);

CREATE TABLE IF NOT EXISTS `servers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `box_id` INT NOT NULL,
    `game` ENUM('cs16', 'cs2', 'minecraft') NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `port` INT NOT NULL,
    `max_players` INT DEFAULT 32,
    `start_map_or_world` VARCHAR(64) DEFAULT 'de_dust2',
    `ram_mb` INT DEFAULT 2048,
    `install_path` VARCHAR(255) NOT NULL,
    `screen_name` VARCHAR(64) NOT NULL,
    `status` ENUM('online', 'offline') DEFAULT 'offline',
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`box_id`) REFERENCES `boxes`(`id`) ON DELETE CASCADE
);
