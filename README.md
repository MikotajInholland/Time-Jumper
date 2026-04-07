Swap between Past and Future to change the environment in real-time.
![Recording2026-04-07184348-ezgif com-video-to-gif-converter](https://github.com/user-attachments/assets/7c3a615f-0938-4e15-997f-d75fdfe17e0f)


# Time Jumper

Hey there! Welcome to **Time Jumper**. 

This is a full-stack, 2.5D parkour maze hybrid game built from scratch. You navigate procedurally generated levels and use a core mechanic: **swapping between two timelines (Past and Future)**. Because time changes things, swapping alters the walls and floors around you, allowing you to bypass obstacles and find a path to the goal.

The game features a daily seed system (so everyone in the world plays the exact same layout every 24 hours) and a global leaderboard to compete for the fastest time.

---

## The Stack (Hows & Whys)

This isn't just a simple game frontend; it's a complete full-stack application with strict server authority to prevent cheating. Here is what makes it tick and why I chose these tools:

### 1. The Game Engine: Vanilla JS & HTML5 Canvas
You might wonder: *"Why build a 2.5D engine from scratch instead of using Three.js, Babylon, or Unity?"*
- **The Learning Experience:** Building a Digital Differential Analysis (DDA) raycaster (the exact same math used in *Wolfenstein 3D* and *Doom*) is incredibly fun and teaches you low-level rendering and vector math.
- **Strict Physics Control:** To prevent cheating on the leaderboard, the server needs to verify your run. By writing the physics engine in pure, deterministic Javascript, I could easily port the **exact same math** to PHP on the backend. If I used a black-box physics engine from a large library, server-side validation would be a nightmare.
- **Performance & Size:** The entire game engine is just a few kilobytes of code. It boots instantly.

### 2. The Backend: Laravel 11 (PHP)
I chose Laravel because it is arguably the best framework for rapidly building robust APIs with heavy logic. 
- **Anti-Cheat Validation:** When you cross the finish line, the client sends a "replay" (a list of all your inputs: W, A, S, D, Space, Mouse movements, and precise timestamps). The Laravel backend actually boots up a headless physics simulation, replays your exact inputs against the daily seed's map, and verifies that you truly reached the goal in the time you claimed. 
- **Sanctum Auth:** I use Laravel Sanctum for dead-simple, secure API token authentication for user accounts.
- **Database Management:** Eloquent ORM makes saving daily scores and fetching the leaderboard effortless.

### 3. The Frontend App: Vue 3, Vue Router, Pinia & Vite
While the game itself runs in an HTML `<canvas>`, the surrounding UI (menus, HUD, authentication, leaderboards) is built with Vue.
- **Vue 3:** Excellent for reactive interfaces. It overlays seamlessly on top of the game canvas to show health, time, and crosshairs.
- **Pinia:** Manages the global state (like "is the user logged in?" and "what is the current daily seed?").
- **Vite:** It is blisteringly fast. When developing, changes reflect in the browser instantly. (I also added a custom `npm run build:readable` script if you ever want to inspect the compiled output without minification).

---

## Game Mechanics

- **Procedural Generation:** Every day at midnight (UTC), a new seed is generated. The PHP backend and the JS frontend both use this seed to generate identical Past and Future maps. The generator is smart enough to guarantee that there is always a valid path to the goal.
- **Time Swapping:** Hit `Shift` to swap timelines. The layout of walls and gaps will change. Use this to bypass walls or avoid falling into the void. Be careful: you can't swap if the other timeline doesn't have a floor beneath your feet!
- **Movement:** Standard `W A S D` or Arrow Keys. Mouse to look around. `Space` to jump.

---

## Running the Project from Scratch (Docker)

I've designed the environment so that **anyone** can clone this repository and get the entire stack running in a few minutes. You don't need PHP, Composer, Node, or MySQL installed on your host machine. 

**All you need is an IDE (like VS Code or Cursor), Git, and Docker Desktop.**

### Step 1: Clone the Repository
```bash
git clone <your-repo-url>
cd "WEBDEV2 Project"
```

### Step 2: Spin it up with Docker
I have heavily automated the Docker configuration. The startup scripts will automatically install dependencies, set up `.env` files, generate app keys, and migrate the database.

Just run:
```bash
docker-compose up --build -d
```
*(The `-d` runs it in the background so it doesn't lock up your terminal. Drop the `-d` if you want to watch the logs stream in real-time).*

**What Docker is doing behind the scenes:**
1. **`mysql` container:** Boots up a MySQL 8 database.
2. **`api` container (Laravel):** Installs PHP dependencies via Composer, generates the `.env`, waits for MySQL to be ready, runs all database migrations, and boots PHP-FPM.
3. **`frontend` container (Vue):** Installs Node dependencies and starts the Vite development server.
4. **`nginx` container:** Acts as a reverse proxy. It serves the Vue frontend and routes any requests starting with `/api` to the Laravel backend.

### Step 3: Play!
Once the containers are healthy (give it a minute on the first build for `npm install` and `composer install` to finish), open your browser and go to:

 **http://localhost**

You can register an account, log in, and start playing. 

### Useful Docker Commands for Development:
- **View all logs:** `docker-compose logs -f`
- **View only backend logs:** `docker-compose logs -f api`
- **Run a Laravel Artisan command:** `docker-compose exec api php artisan <command>`
- **Restart everything:** `docker-compose restart`
- **Tear down (and wipe the database):** `docker-compose down -v`

---

## Deploying with a Cloud Database

If you want to host this online and use a managed database (like AWS RDS, PlanetScale, or Azure MySQL) instead of the local Docker MySQL container:

1. Create a `.env` file in the `backend/` folder (or set the environment variables in your hosting provider's dashboard).
2. Add your cloud database credentials:
   ```env
   DB_HOST=your-cloud-db-host.com
   DB_PORT=3306
   DB_DATABASE=your_db_name
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   ```
3. Remove (or comment out) the `mysql` service in `docker-compose.yml` so the app doesn't try to boot a local database.

Laravel will automatically read your cloud credentials and connect to the remote server. No code changes required!

---

Enjoy the game, and good luck beating the daily record! ⏱️🏃‍♂️
