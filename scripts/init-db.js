require('dotenv').config();

const { getPool } = require('../src/db');

const createUsersTable = `
  CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(255) NOT NULL,
    interested_in VARCHAR(120) NULL,
    budget VARCHAR(80) NULL,
    project_details TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY users_email_unique (email)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
`;

async function initializeDatabase() {
  const pool = getPool();

  try {
    await pool.query(createUsersTable);
    console.log('users table is ready');
  } finally {
    await pool.end();
  }
}

initializeDatabase().catch((error) => {
  console.error('Database initialization failed:', error.message);
  process.exitCode = 1;
});
