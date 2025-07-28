# Optimal Server Settings for 4GB RAM / 2 vCPU

These settings are optimized for a WordPress server running on a t3.medium instance or equivalent. The goal is to balance performance and stability, leaving enough memory for the operating system to function without relying on slow disk swap.

---

### 1. MySQL (`/etc/mysql/mysql.conf.d/z-custom.cnf`)

- **`innodb_buffer_pool_size = 1G`**: (25% of RAM) The most critical setting for database performance.
- **`key_buffer_size = 32M`**: A small buffer for any MyISAM tables.
- **`max_connections = 100`**: A safe limit for this server size.

---

### 2. PHP-FPM (`/etc/php/XXX/fpm/pool.d/www.conf`)

- **`pm = dynamic`**
- **`pm.max_children = 30`**: Prevents PHP from consuming all available memory.
- **`pm.start_servers = 10`**
- **`pm.min_spare_servers = 5`**
- **`pm.max_spare_servers = 15`**
- **`pm.max_requests = 500`**

---

### 3. PHP (`/etc/php/XXX/fpm/php.ini`)

- **`memory_limit = 128M`**: A safe limit for individual WordPress scripts.
- **`opcache.memory_consumption = 128`**: A reasonable size for the PHP opcode cache.

---

### 4. Redis (`/etc/redis/redis.conf`)

- **`maxmemory 256mb`**: Sufficient for object caching without wasting RAM.
- **`maxmemory-policy allkeys-lru`**

---

### 5. Nginx (`/etc/nginx/nginx.conf`)

- **`worker_processes auto;`**: Automatically scales to the number of CPU cores (2).
