<?php

namespace App\Repository;

use App\Entity\Counter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Counter>
 */
class CounterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Counter::class);
    }

    public function getOrCreateCounter(): Counter
    {
        $counter = $this->find(1);

        if (!$counter) {
            $counter = new Counter();
            $this->getEntityManager()->persist($counter);
            $this->getEntityManager()->flush();
        }

        return $counter;
    }
}
