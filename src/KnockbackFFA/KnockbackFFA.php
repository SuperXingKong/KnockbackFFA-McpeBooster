<?php

namespace KnockbackFFA;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\scheduler\PluginTask;
use pocketmine\Server;
use pocketmine\utils\Config;
use pocketmine\tile\Sign;
use pocketmine\Player;
use pocketmine\lang\BaseLang;
use pocketmine\math\Vector3;
use pocketmine\item\Item;
use pocketmine\level\sound\{ClickSound};
use pocketmine\item\enchantment\Enchantment;
use pocketmine\entity\Effect;

use pocketmine\command\{Command, CommandSender};
use pocketmine\event\block\{SignChangeEvent, BlockBreakEvent, BlockPlaceEvent};
use pocketmine\event\player\{PlayerInteractEvent, PlayerMoveEvent, PlayerDropItemEvent, PlayerQuitEvent, PlayerJoinEvent, PlayerExhaustEvent};
use pocketmine\event\entity\{EntityDamageByEntityEvent, EntityDamageEvent, EntityLevelChangeEvent};

use pocketmine\network\mcpe\protocol\{AdventureSettingsPacket};

class KnockbackFFA extends PluginBase implements Listener{
	
	public function onEnable() {
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		
		$this->saveDefaultConfig();
		$this->prefix = $this->getConfig()->get("Prefix")." ";
		$this->signprefix = $this->getConfig()->get("Prefix");
		$this->titlestatus = $this->getConfig()->get("Title");
		
		$this->getLogger()->info($this->prefix . "by §6McpeBooster§7!");
		
		$lang = $this->getConfig()->get("language", BaseLang::FALLBACK_LANGUAGE);
		$this->baseLang = new BaseLang($lang, $this->getFile() . "resources/");
		
		$this->getLogger()->info($this->prefix . "Language: ".$lang);
		
		$this->arenas = $this->getConfig()->get("Arenas");
		foreach($this->arenas as $a){
			$this->getServer()->loadLevel($a);
			$level = $this->getServer()->getLevelByName($a);
			$level->setTime(0);
            $level->stopTime();
		}
		
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new checkLevel($this), 20);
	}
	
	public function onLoad(){
		$players = $this->getServer()->getOnlinePlayers();
		$time = mktime(date("H"), date("i"), date("s"));
		foreach($players as $p){
			$name = strtolower($p->getName());
			$this->lastDmg[$name] = "emp ty";
			$this->lastKillstreak[$name] = 0;
			$this->lastRespawn[$name] = $time;
		}
	}
	
	public function getLanguage() : BaseLang {
		return $this->baseLang;
	}
	
	/**
	* @param Player $player
	* @param $line1
	* @param $line2
	*/
	
	public function Title(Player $player, string $line1, string $line2){
		if($this->titlestatus){
			if($this->getServer()->getName() == "PocketMine-MP"){
				$player->addTitle($line1, $line2);
				return;
			}else{
				$player->sendTitle($line1, $line2);
			return;
			}
		}
	}	
	
	#Events
	
	public function onSignCreate(SignChangeEvent $event){
		if($event->getLine(0) == "KnockbackFFA"){
			if($event->getPlayer()->isOp()){
				if(in_array($event->getLine(1), $this->arenas)){
					
					$event->setLine(0, $this->signprefix);
					$event->setLine(2, "§aJoin");
					$arenalevel = $this->getServer()->getLevelByName($event->getLine(1));
					$playercount = count($arenalevel->getPlayers());
					$maxplayer = $this->getConfig()->get("MaxPlayer");
					$event->setLine(3, "§f".$playercount." §7/ §c".$mayplayer);
					return;
				}
			}
			$event->setCancelled();
			return;
		}
	}
	
	public function onInteract(PlayerInteractEvent $event) {
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$tile = $player->getLevel()->getTile($block);
		
		if($tile instanceof Sign) {
			$text = $tile->getText();
			if($text[0] == $this->signprefix) {
				if($text[2] == "§aJoin") {
					$this->ArenaJoin($player, $text[1]);
					return;
				}else{
					$player->sendMessage($this->prefix . $this->getLanguage()->get("arena.ingame"));
					return;
				}
			}
		}
	}
	
	public function onDamage(EntityDamageEvent $event){
		if($event->getEntity() instanceof Player){
			$entity = $event->getEntity();
			$welt = $entity->getLevel()->getFolderName();
			if(in_array($welt, $this->arenas)){
				$cause = $event->getCause();
				if ($cause == EntityDamageEvent::CAUSE_ENTITY_ATTACK){
					if ($event instanceof EntityDamageByEntityEvent){
						$entity->setHealth(20);
						$entity->setFood(20);
						
						$damager = $event->getDamager();
						if($damager instanceof Player){
							
							$x = $entity->getX();
							$y = $entity->getY();
							$z = $entity->getZ();
							
							$xx = $entity->getLevel()->getSafeSpawn()->getX();
							$yy = $entity->getLevel()->getSafeSpawn()->getY();
							$zz = $entity->getLevel()->getSafeSpawn()->getZ();
							$sr = $this->getConfig()->get("ProtectionRadius");
							
							if(abs($xx - $x) < $sr && abs($yy - $y) < $sr && abs($zz - $z) < $sr){
								$event->setCancelled();
								$damager->sendMessage($this->prefix . $this->getLanguage()->get("player.spawnprotection"));
								return;
							}
							
							$item = $damager->getInventory()->getItemInHand()->getId();
							if($item == 280){
								$x = $damager->getDirectionVector()->x;
								$z = $damager->getDirectionVector()->z;
								$entity->knockBack($entity, 0 , $x, $z, 0.6);
								$this->lastDmg[strtolower($entity->getName())] = strtolower($damager->getName());
								return;
							}
						}
					}
				}
			}
		}
	}
	
	public function onMove(PlayerMoveEvent $event){
		$player = $event->getPlayer();
		$name = strtolower($player->getName());
		$welt = $player->getLevel()->getFolderName();
		if(in_array($welt, $this->arenas)){
			if($player->y < 3){
				$this->PlayerRespawn($player);
			}
		}
	}
	
	public function onBreak(BlockBreakEvent $event){
		$player = $event->getPlayer();
		$welt = $player->getLevel()->getFolderName();
		if(in_array($welt, $this->arenas)){
			$event->setCancelled();
		}
	}
	
	public function onPlace(BlockPlaceEvent $event){
		$player = $event->getPlayer();
		$welt = $player->getLevel()->getFolderName();
		if(in_array($welt, $this->arenas)){
			$event->setCancelled();
		}
	}
	
	public function onDrop(PlayerDropItemEvent $event){
		$player = $event->getPlayer();
		$welt = $player->getLevel()->getFolderName();
		if(in_array($welt, $this->arenas)){
			$event->setCancelled();
		}
	}
	
	public function onExhaust(PlayerExhaustEvent $event){
		$player = $event->getPlayer();
		$welt = $player->getLevel()->getFolderName();
		if(in_array($welt, $this->arenas)){
			$player->setFood(20);
		}
	}
	
	public function onLevelChange(EntityLevelChangeEvent $event){
		$entity = $event->getEntity();
		if($entity instanceof Player){
			$player = $entity;
			//$welt = $event->getOrigin()->getFolderName();
			$arena = $event->getTarget()->getFolderName();
			if(in_array($arena, $this->arenas)){
		
				$pk = new AdventureSettingsPacket();
				$pk->worldImmutable = true;
				$pk->autoJump = false;
				$player->dataPacket($pk);				
			}
		}
	}
	
	public function onJoin(PlayerJoinEvent $event){
		$player = $event->getPlayer();
		$name = strtolower($player->getName());
		$this->lastDmg[$name] = "emp ty";
		$this->lastKillstreak[$name] = 0;
		$this->lastRespawn[$name] = mktime(date("H"), date("i"), date("s"));
		$this->updateSign();
	}
	
	public function onQuit(PlayerQuitEvent $event){
		$this->updateSign();
	}
	
	#Sign
	
	/*
	* @param $arena
	*/
	
	private function updateSign(){
		$lobby = $this->getServer()->getDefaultLevel();
		if($this->getServer()->isLevelLoaded($lobby->getFolderName())){
			foreach($lobby->getTiles() as $tile){
				if($tile instanceof Sign){
					$signt = $tile->getText();
					if($signt[0] == $this->signprefix){
						$arena = $signt[1];
						$arenalevel = $this->getServer()->getLevelByName($arena);
						$playercount = count($arenalevel->getPlayers());
						$maxplayer = $this->getConfig()->get("MaxPlayer");
						if($playercount >= $maxplayer){
							$tile->setText($signt[0], $arena, "§cFull", "§f".$playercount." §7/ §c".$maxplayer);
						}else{
							$tile->setText($signt[0], $arena, "§aJoin", "§f".$playercount." §7/ §c".$maxplayer);
						}
					}
				}
			}
		}
	}
	
	#Game
	
	/*
	* @param Player $player
	* @param $arena
	*/
	
	private function ArenaJoin(Player $player, string $arena){
		if(!$this->getServer()->isLevelLoaded($arena)){
			$this->getServer()->loadLevel($arena);
		}
		
		$player->setGamemode(0);
		$this->giveKit($player);
		
		$arenalevel = $this->getServer()->getLevelByName($arena);
		$player->teleport($arenalevel->getSafeSpawn());
		$name = strtolower($player->getName());
		$this->lastKillstreak[$name] = 0;
		$player->sendMessage($this->prefix.$this->getLanguage()->get("player.join"));
		
		$this->updateSign();
	}
	
	#Player
	
	/*
	* @param Player $player
	*/
	
	private function PlayerRespawn(Player $player){
		$name = strtolower($player->getName());
		$time =  mktime(date("H"), date("i"), date("s"));
		if($this->lastRespawn[$name] < $time){
			$level = $player->getLevel();
			$player->teleport($level->getSafeSpawn());
			
			if($this->lastDmg[$name] == "emp ty"){		
				$this->Title($player, "§cDeath", "");
				$player->sendMessage($this->prefix.$this->getLanguage()->get("player.void"));
			}else{
				$dname = $this->lastDmg[$name];
				
				$damager = $this->getServer()->getPlayer($dname);
				$this->lastKillstreak[$dname] = $this->lastKillstreak[$dname] + 1;
				$ks = [5, 10, 15, 20, 25, 30, 40, 50];
				if(in_array($this->lastKillstreak[$dname], $ks)){
					$players = $level->getPlayers();
					$msg = $this->getLanguage()->get("player.killstreak");
					$msg = str_replace("{player}", $damager->getName(), $msg);
					$msg = str_replace("{killstreak}", $this->lastKillstreak[$dname], $msg);
					
					foreach($players as $p){
						$p->sendMessage($this->prefix. $msg);
					}
				}
				$this->Title($player, "§cDeath", $damager->getName());
				$msg = $this->getLanguage()->get("player.death");
				$msg = str_replace("{player}", $damager->getName(), $msg);
				$player->sendMessage($this->prefix.$msg);
				
				$this->Title($damager, "§aKill", $player->getName());
				$msg = $this->getLanguage()->get("player.kill");
				$msg = str_replace("{player}", $player->getName(), $msg);
				$damager->sendMessage($this->prefix.$msg);
			
			}
			$this->giveKit($player);
			$this->lastDmg[$name] = "emp ty";
			$this->lastKillstreak[$name] = 0;
			$this->lastRespawn[$name] = mktime(date("H"), date("i"), date("s") + 2);
		}
	}
	
	#Kit
	
	/*
	* @param Player $player
	*/
	
	private function giveKit(Player $player){
		$waffe = 280;
		
		$player->setHealth(20);
		$player->setFood(20);
		$inv = $player->getInventory();
		$inv->clearAll();
		$item = Item::get($waffe);
		$enchantment = Enchantment::getEnchantment(20);
		$enchantment->setLevel(10);
		$item->addEnchantment($enchantment);
		$inv->setItem(0, $item);
		$effect = Effect::getEffect(1);
        $effect->setAmplifier(1)->setVisible(false)->setDuration(10000000000);
        $player->addEffect($effect);
		$level = $player->getLevel();
		
		$effect = Effect::getEffect(8);
        $effect->setAmplifier(1)->setVisible(false)->setDuration(10000000000);
        $player->addEffect($effect);
		$level = $player->getLevel();
		$level->addSound(new ClickSound($player));
	}
	
	#Task
	
	public function checkLevelTask(){
		$players = $this->getServer()->getOnlinePlayers();
		foreach($players as $p){
			$welt = $p->getLevel()->getFolderName();
			if(in_array($welt, $this->arenas)){
				$name = strtolower($p->getName());
				$level = $this->lastKillstreak[$name];
				$p->sendPopup("§6".$level);
			}
		}
	}
}


class checkLevel extends PluginTask {
	
	public function __construct(KnockbackFFA $plugin){
		parent::__construct($plugin);
		$this->plugin = $plugin;
	}
	
	public function onRun($currentTick){
		$this->plugin->checkLevelTask();
	}
}