<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\DAV\Files;

use OCA\DAV\Connector\Sabre\Node;
use OCP\Files\Storage\IPersistentLockingStorage;
use OCP\Lock\Persistent\ILock;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Locks;
use Sabre\DAV\Locks\Backend\BackendInterface;
use Sabre\DAV\Tree;
use OCP\AppFramework\Utility\ITimeFactory;

class FileLocksBackend implements BackendInterface {

	/** @var Tree */
	private $tree;
	/** @var bool */
	private $useV1;
	/** @var ITimeFactory */
	private $timeFactory;

	public function __construct($tree, $useV1, $timeFactory) {
		$this->tree = $tree;
		$this->useV1 = $useV1;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * Returns a list of Sabre\DAV\Locks\LockInfo objects
	 *
	 * This method should return all the locks for a particular uri, including
	 * locks that might be set on a parent uri.
	 *
	 * If returnChildLocks is set to true, this method should also look for
	 * any locks in the subtree of the uri for locks.
	 *
	 * @param string $uri
	 * @param bool $returnChildLocks
	 * @return array
	 */
	public function getLocks($uri, $returnChildLocks) {
		try {
			$node = $this->tree->getNodeForPath($uri);

			if (!$node instanceof Node) {
				return [];
			}

			$storage = $node->getFileInfo()->getStorage();
			if (!$storage->instanceOfStorage(IPersistentLockingStorage::class)) {
				return [];
			}

			/** @var IPersistentLockingStorage $storage */
			$locks = $storage->getLocks($node->getFileInfo()->getInternalPath(), $returnChildLocks);
		} catch (NotFound $e) {
			// get parent storage and check for locks on the target path
			list($parentPath, $childPath) = \Sabre\Uri\split($uri);
			if ($parentPath === '') {
				return [];
			}

			try {
				$node = $this->tree->getNodeForPath($parentPath);
			} catch (NotFound $e) {
				return [];
			}

			if (!$node instanceof Node) {
				return [];
			}

			// use storage of parent
			$storage = $node->getFileInfo()->getStorage();
			if (!$storage->instanceOfStorage(IPersistentLockingStorage::class)) {
				return [];
			}

			/** @var IPersistentLockingStorage $storage */
			$locks = $storage->getLocks($node->getFileInfo()->getInternalPath() . '/' . $childPath, $returnChildLocks);
		}

		$davLocks = [];
		foreach ($locks as $lock) {
			$lockInfo = new Locks\LockInfo();
			$fileName = $lock->getAbsoluteDavPath();

			if ($this->useV1) {
				$lockInfo->uri = $fileName;
			} else {
				$uid = $lock->getDavUserId();
				$lockInfo->uri = "files/$uid/$fileName";
			}
			$lockInfo->token = $lock->getToken();
			$lockInfo->created = $lock->getCreatedAt();
			$lockInfo->depth = $lock->getDepth();
			$lockInfo->owner = $lock->getOwner();
			if ($lock->getScope() === ILock::LOCK_SCOPE_EXCLUSIVE) {
				$lockInfo->scope = Locks\LockInfo::EXCLUSIVE;
			} else {
				$lockInfo->scope = Locks\LockInfo::SHARED;
			}
			$lockInfo->timeout = $lock->getTimeout() - ($this->timeFactory->getTime() - $lock->getCreatedAt());

			$davLocks[] = $lockInfo;
		}
		return $davLocks;
	}

	/**
	 * Locks a uri
	 *
	 * @param string $uri
	 * @param Locks\LockInfo $lockInfo
	 * @return bool
	 */
	public function lock($uri, Locks\LockInfo $lockInfo) {
		try {
			$node = $this->tree->getNodeForPath($uri);
		} catch (NotFound $e) {
			return false;
		}
		if (!$node instanceof Node) {
			return false;
		}

		$storage = $node->getFileInfo()->getStorage();
		if (!$storage->instanceOfStorage(IPersistentLockingStorage::class)) {
			return false;
		}

		/** @var IPersistentLockingStorage $storage */
		$lock = $storage->lockNodePersistent($node->getFileInfo()->getInternalPath(), [
			'token' => $lockInfo->token,
			'scope' => $lockInfo->scope === Locks\LockInfo::EXCLUSIVE ? ILock::LOCK_SCOPE_EXCLUSIVE : ILock::LOCK_SCOPE_SHARED,
			'depth' => $lockInfo->depth,
			'owner' => $lockInfo->owner,
			'timeout' => $lockInfo->timeout
		]);

		// in case the timeout has not been accepted, adjust in lock info
		$lockInfo->timeout = $lock->getTimeout();

		return !empty($lock);
	}

	/**
	 * Removes a lock from a uri
	 *
	 * @param string $uri
	 * @param Locks\LockInfo $lockInfo
	 * @return bool
	 */
	public function unlock($uri, Locks\LockInfo $lockInfo) {
		try {
			$node = $this->tree->getNodeForPath($uri);
		} catch (NotFound $e) {
			return false;
		}
		if (!$node instanceof Node) {
			return false;
		}

		$storage = $node->getFileInfo()->getStorage();
		if (!$storage->instanceOfStorage(IPersistentLockingStorage::class)) {
			return false;
		}

		/** @var IPersistentLockingStorage $storage */
		return $storage->unlockNodePersistent($node->getFileInfo()->getInternalPath(), [
			'token' => $lockInfo->token
		]);
	}
}
