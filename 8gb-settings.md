# Optimal Server Settings for 8GB RAM / 4 vCPU

These settings are optimized for a WordPress server running on a t3.large instance or equivalent. They provide a significant performance boost over the 4GB configuration by allocating more memory to the database and PHP processes.

---

### 1. MySQL (`/etc/mysql/mysql.conf.d/z-custom.cnf`)

- **`innodb_buffer_pool_size = 3G`**: (37.5% of RAM) A substantial cache for excellent database performance.
- **`key_buffer_size = 128M`**: A modest buffer for MyISAM tables.
- **`max_connections = 200`**: Allows for more simultaneous database connections.

---

### 2. PHP-FPM (`/etc/php/XXX/fpm/pool.d/www.conf`)

- **`pm = dynamic`**
- **`pm.max_children = 60`**: Allows for a high number of concurrent PHP processes.
- **`pm.start_servers = 15`**
- **`pm.min_spare_servers = 10`**
- **`pm.max_spare_servers = 20`**
- **`pm.max_requests = 500`**

---

### 3. PHP (`/etc/php/XXX/fpm/php.ini`)

- **`memory_limit = 256M`**: Provides more memory for complex plugins and backend tasks.
- **`opcache.memory_consumption = 256`**: A larger opcode cache for better PHP performance.

---

### 4. Redis (`/etc/redis/redis.conf`)

- **`maxmemory 512mb`**: A larger cache for storing more objects in memory.
- **`maxmemory-policy allkeys-lru`**

---

### 5. Nginx (`/etc/nginx/nginx.conf`)

- **`worker_processes auto;`**: Automatically scales to the number of CPU cores.
