<?php
declare(strict_types=1);

namespace Prim69\Replay;

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\World;
use function get_class;
use function in_array;
use function microtime;
use function round;

class Main extends PluginBase implements Listener {

    /** @var array */
    public array $recording = [];

    /** @var array */
    public array $saved = [];

    /** @var array */
    public array $positions = [];

    public const IGNORE_SERVERBOUND = [
        TextPacket::class
    ];

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getCommandMap()->register($this->getName(), new ReplayCommand($this));
    }

    public function showRecording(Player $player, Player $target): void {
        $this->getScheduler()->scheduleRepeatingTask(new ReplayTask($player, $target, $this), 1);
    }

    public function isRecording(string $name): bool {
        return isset($this->recording[$name]);
    }


    /** @priority MONITOR */
    public function onBlockPlace(BlockPlaceEvent $event): void {
        $player = $event->getPlayer();
        if(!$this->isRecording($player->getName())) return;
        foreach ($event->getTransaction()->getBlocks() as [$x,$y,$z, $block]) {
            if($block instanceof Block) {
                $this->recording[$player->getName()]["blocks"][(string) round(microtime(true), 2)] = $block;
                $blockPos = $block->getPosition();
                if(!isset($this->recording[$player->getName()]["preBlocks"][$hash = World::blockHash($blockPos->x, $blockPos->y, $blockPos->z)])) {
                    $this->recording[$player->getName()]["preBlocks"][$hash] = $block->getAffectedBlocks();
                }
            }
        }
    }

    /** @priority MONITOR */
    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        if(!$this->isRecording($player->getName())) return;
        $air = VanillaBlocks::AIR();
        $blockPos = $event->getBlock()->getPosition();
        $air->position($player->getWorld(), $blockPos->x, $blockPos->y, $blockPos->z);
        $this->recording[$player->getName()]["blocks"][(string) round(microtime(true), 2)] = $air;
        if(!isset($this->recording[$player->getName()]["preBlocks"][$hash = World::blockHash($blockPos->x, $blockPos->y, $blockPos->z)])) {
            $this->recording[$player->getName()]["preBlocks"][$hash] = $event->getBlock();
        }
    }

    public function onReceive(DataPacketReceiveEvent $event): void {
        $pk = $event->getPacket();
        $player = $event->getOrigin()->getPlayer();
        if($player !== null && $this->isRecording($player->getName())) {
            if($pk instanceof PlayerAuthInputPacket) {
                $this->recording[$player->getName()]["packets"][(string) round(microtime(true), 2)] = MovePlayerPacket::simple(
                    $player->getId(),
                    $pk->getPosition(),
                    $pk->getPitch(),
                    $pk->getYaw(),
                    $pk->getHeadYaw(),
                    MovePlayerPacket::MODE_NORMAL,
                    true,
                    0,
                    0
                );
            } elseif($pk instanceof InventoryTransactionPacket) {
                $this->recording[$player->getName()]["packets"][(string) round(microtime(true), 2)] = $pk;
            } elseif($pk instanceof ClientboundPacket && !in_array(get_class($pk), self::IGNORE_SERVERBOUND)) {
                $this->recording[$player->getName()]["packets"][(string) round(microtime(true), 2)] = $pk;
            }
        }
    }

    public function onQuit(PlayerQuitEvent $event): void {
        $name = $event->getPlayer()->getName();
        if($this->isRecording($name)) {
            $this->saved[$name] = $this->recording[$name];
            unset($this->recording[$name]);
        }
    }
}