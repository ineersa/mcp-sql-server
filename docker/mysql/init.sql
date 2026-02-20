-- Allow test_user to create triggers and stored routines with binary logging enabled
SET GLOBAL log_bin_trust_function_creators = 1;

-- Grant additional privileges needed for test fixtures
GRANT TRIGGER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE ON *.* TO 'test_user'@'%';
FLUSH PRIVILEGES;
