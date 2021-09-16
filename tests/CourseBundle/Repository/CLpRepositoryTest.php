<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\Tests\CourseBundle\Repository;

use Chamilo\CourseBundle\Entity\CLp;
use Chamilo\CourseBundle\Repository\CLpRepository;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;

class CLpRepositoryTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testCreateLp(): void
    {
        self::bootKernel();

        $repo = self::getContainer()->get(CLpRepository::class);

        $course = $this->createCourse('new');
        $teacher = $this->createUser('teacher');

        $lp = (new CLp())
            ->setName('lp')
            ->setDescription('desc')
            ->setTheme('chamilo')
            ->setAccumulateScormTime(100)
            ->setAccumulateWorkTime(100)
            ->setAuthor('author')
            ->setContentMaker('maker')
            ->setContentLocal('local')
            ->setForceCommit(false)
            ->setUseMaxScore(100)
            ->setSubscribeUsers(1)
            ->setJsLib('lib')
            ->setHideTocFrame(true)
            ->setRef('ref')
            ->setPath('path')
            ->setAutolaunch(0)
            ->setCategory(null)
            ->setParent($course)
            ->setCreator($teacher)
            ->setLpType(CLp::LP_TYPE)
        ;
        $this->assertHasNoEntityViolations($lp);
        $repo->createLp($lp);

        $this->assertNotNull($lp->getResourceNode());
        $this->assertSame(1, $lp->getItems()->count());
        $this->assertFalse($lp->hasCategory());
        $this->assertSame('lp', (string) $lp);
        $this->assertSame(1, $repo->count([]));

        $link = $repo->getLink($lp, $this->getContainer()->get('router'));
        $this->assertSame('/main/lp/lp_controller.php?lp_id='.$lp->getIid().'&action=view', $link);
    }

    public function testFindAllByCourse(): void
    {
        self::bootKernel();

        $repo = self::getContainer()->get(CLpRepository::class);

        $course = $this->createCourse('new');
        $teacher = $this->createUser('teacher');

        $lp = (new CLp())
            ->setName('lp')
            ->setParent($course)
            ->setCreator($teacher)
            ->setLpType(CLp::LP_TYPE)
            ->addCourseLink($course)
        ;
        $repo->createLp($lp);

        $qb = $repo->findAllByCourse($course);
        $this->assertSame(1, \count($qb->getQuery()->getResult()));
    }
}
