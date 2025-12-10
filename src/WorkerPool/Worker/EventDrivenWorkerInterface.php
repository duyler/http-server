<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Worker;

use Duyler\HttpServer\Server;

/**
 * Event-Driven Worker Interface
 *
 * Используется для запуска полноценных приложений с собственным event loop в Worker Pool.
 *
 * ## Архитектура
 *
 * В отличие от WorkerCallbackInterface, который вызывается для каждого соединения,
 * EventDrivenWorkerInterface запускается ОДИН РАЗ при старте воркера и позволяет
 * приложению иметь полный контроль над event loop.
 *
 * ## Поток работы
 *
 * 1. Master запускает Worker процесс (fork)
 * 2. Worker вызывает `run(workerId, server)` ОДИН РАЗ
 * 3. Приложение инициализируется (Database, EventBus, etc.)
 * 4. Приложение запускает свой event loop (while true)
 * 5. Master передает соединения через `Server::addExternalConnection()`
 * 6. Приложение опрашивает `Server::hasRequest()` в своем event loop
 * 7. Запросы обрабатываются асинхронно через Event Bus
 * 8. Ответы отправляются через `Server::respond()` в другом тике
 *
 * ## Пример использования
 *
 * ```php
 * class MyApp implements EventDrivenWorkerInterface
 * {
 *     public function run(int $workerId, Server $server): void
 *     {
 *         // ВАЖНО: НЕ вызывайте $server->start()!
 *         // Master уже управляет сокетом и передает соединения в Server.
 *         // Server автоматически помечается как "running" в Worker Pool режиме.
 *
 *         // Инициализация (ОДИН РАЗ)
 *         $eventBus = new EventBus();
 *         $db = new Database();
 *
 *         // Event loop приложения (БЕСКОНЕЧНЫЙ)
 *         while (true) {
 *             // Tick 1: Получить запросы от Worker Pool
 *             if ($server->hasRequest()) {
 *                 $request = $server->getRequest();
 *                 $eventBus->dispatch('http.request', $request);
 *             }
 *
 *             // Tick 2: Обработать события
 *             $eventBus->tick();
 *
 *             // Tick 3: Отправить готовые ответы
 *             if ($server->hasPendingResponse()) {
 *                 $response = $eventBus->getResponse();
 *                 $server->respond($response);
 *             }
 *
 *             usleep(1000); // 1ms
 *         }
 *     }
 * }
 * ```
 *
 * @see WorkerCallbackInterface Для синхронной обработки соединений
 */
interface EventDrivenWorkerInterface
{
    /**
     * Запускает приложение в воркере
     *
     * Метод вызывается ОДИН РАЗ при старте воркера и НИКОГДА не возвращается.
     * Приложение должно запустить свой собственный event loop внутри этого метода.
     *
     * Master процесс будет передавать новые соединения в Server через
     * метод addExternalConnection(). Приложение должно периодически вызывать
     * Server::hasRequest() для проверки наличия новых запросов.
     *
     * @param int $workerId ID воркера (1, 2, 3, ..., N)
     * @param Server $server Server instance для взаимодействия с Worker Pool
     *                       - hasRequest() - проверить наличие запросов
     *                       - getRequest() - получить следующий запрос
     *                       - respond() - отправить ответ
     *                       - hasPendingResponse() - проверить наличие pending ответов
     *
     * @return void (never returns - infinite loop inside)
     */
    public function run(int $workerId, Server $server): void;
}
