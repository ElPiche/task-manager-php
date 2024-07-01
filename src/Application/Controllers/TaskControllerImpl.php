<?php

namespace App\Application\Controllers;

use App\Domain\Entities\Enums\RoleType;
use App\Domain\Entities\Link;
use App\Domain\Entities\Task;
use App\Domain\Entities\User;
use App\Domain\Repositories\LinkRepository;
use App\Domain\Repositories\ProjectRepository;
use App\Domain\Repositories\TaskRepository;
use App\Domain\Repositories\UserRepository;
use App\Interface\Dtos\LinkDTO;
use App\Interface\Dtos\UserDTO;
use App\Interface\TaskController;
use App\Interface\Dtos\TaskDTO;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Exception;
use App\Domain\Entities\Enums\State;
use DateTimeImmutable;

class TaskControllerImpl implements TaskController{

    //------------------------------------------------------------------------------------------
    //los controladores van a tener acceso a varios repositorios para propósitos de lógica
    private TaskRepository $taskRepository;
    private ProjectRepository $projectRepository;
    private  LinkRepository $linkRepository;
    private userRepository $userRepository;

    //------------------------------------------------------------------------------------------
    //hay que añadir los repositorios a inyectar al parametro del constructor
    public function __construct(TaskRepository $taskRepository,
                                                ProjectRepository $projectRepository,
                                                LinkRepository $linkRepository,
                                                UserRepository $userRepository){

        $this->taskRepository = $taskRepository;
        $this->projectRepository = $projectRepository;
        $this->linkRepository = $linkRepository;
        $this->userRepository = $userRepository;
    }

    //TESTED uwu

    /**
     * @throws Exception
     */
    public function getTasksByUser(int $userId): ?array
    {
        try {
            //we search for the user we want to get the tasks from
            $userToSearch = $this->userRepository->findById($userId);

            if ($userToSearch == null) {
                throw new Exception("No se pudo encontrar el usuario con ID: " . $userId . " para revisar sus tareas");
            }

            //we get the links from the user
            $userTasks = $userToSearch->getTasks();

            //if (empty($userLinksCheck)){
            if (count($userTasks) < 1) {
                throw new Exception(" el usuario: " . $userToSearch->getName() . " no tiene ninguna tarea asignada");
            }



            //we prepare the array that we will be returning with the TaskDTOs
            $linksDTO = [];
            $tasksDTO = [];

            //we iterate to get all the links that are tasks
            foreach ($userTasks as $taskLink) {

                $linksDTO[] = new LinkDTO(
                    id: $taskLink->getId(),
                    creationDate: $taskLink->getLinkDate(),
                    role: $taskLink->getRole(),
                    creatableDTO: null,
                    user: new UserDTO($userToSearch)
                );


                $task = $taskLink->getCreatable();
                //we create the taskDTO from the task data
                $tasksDTO[] = new TaskDTO($task->getId(),
                    title: $task->getTitle(),
                    description: $task->getDescription(),
                    links: $linksDTO,
                    project: null,
                    taskState: $task->getTaskState(),
                    limitDate: $task->getLimitDate(),
                    userID: null);
            }
            return $tasksDTO;
        }catch (Exception $e){
            throw new Exception($e->getMessage());
        }
    }

    //TESTED UWU
    /**
     * @throws Exception
     */
    public function getTasksByProject(int $projectId): array
    {
        try {
            //we call the repository to get the tasks for a given project ID
            $tasks = $this->taskRepository->findTasksByProject($projectId);

            if($tasks == null){
                //throw Exception if we don't find any
                throw new Exception("No hay tareas para este proyecto con ID: " . $projectId);
            }

            //the array we will fill with the jsons of the taskDTOs of the task we found
            $tasksDTO = [];

            foreach ($tasks as $task){//for each task I got

                //I make the link array so the taskDTO constructor doesn't die
                $links = $task->getLinks();

                //we make the array we will fill with the users of the tasks we find
                $usersDTO = [];

                foreach ($links as $link){
                    //we get the User from the link of the task
                    $userLink = $link->getUser();

                    //we create the userDTO from it
                    $userDTO = new UserDTO($userLink);

                    //we fill the array with the json form of the taskDTO we got from the task
                    $usersDTO[] = $userDTO->jsonSerialize();

                }

                //we create the taskDTO from the task data
                $taskDTO = new TaskDTO($task->getId(),
                    $task->getTitle(),
                    $task->getDescription(),
                    $usersDTO,
                    $task->getProject()->getId(),
                    $task->getTaskState(),
                    $task->getLimitDate(),
                    0);


                //we fill the array with the json form of the taskDTO we got from the task
                $tasksDTO[] = $taskDTO->jsonSerialize();
            }

            return $tasksDTO;

        }catch(Exception $e){
            throw $e;
        }
    }


    //TESTED UWU
    /**
     * @throws Exception
     */
    public function getTaskById(int $taskId): ?TaskDTO
    {
        //we search the task for the ID it has
        $task = $this->taskRepository->findById($taskId);

        //we control that the task we get is not null
        if ($task == null){
            throw new Exception("No se pudo encontrar una tarea con ID: " . $taskId);
        }

        //we get the links from  the task so we can get the users
        $links = $task->getLinks();

        //we create the array we will be filling with the task's users
        $usersDTO = [];
        foreach ($links as $link) {

            //we get the user from the task's link
            $userLink = $link->getUser();

            //we create the user DTO from the user domain object
            $userDTO = new UserDTO($userLink);

            //we fill the array with the json form of the taskDTO we got from the task
            $usersDTO[] = $userDTO->jsonSerialize();
        }

        //we create the taskDTO from the task data
        return new TaskDTO($task->getId(),
        $task->getTitle(),
        $task->getDescription(),
        $usersDTO, //this is NOT gonna work lmao. . .
        $task->getProject()->getId(),
        $task->getTaskState(),
        $task->getLimitDate(),
        null);
    }



    // TESTED UWU
    //TODO: add a function that lets me add an array of users to a given task

    /**
     * @throws ORMException
     * @throws Exception
     */
    public function createTask(TaskDTO $taskDTO): ?int
    {
        //We find the project that the task belongs to
        $projectTask = $this->projectRepository->findById($taskDTO->getProject());

        try { //let's try to create & persist the task

            //we control that the project is not null
            if ($projectTask == null) {
                throw new Exception("No se pudo encontrar el proyecto con ID: " . $taskDTO->getProject() );
            }
            //we create the domain object of the Task to add
            $task = new Task(null,
                $taskDTO->getTitle(),
                $taskDTO->getLimitDate(),
                $taskDTO->getDescription(),
                $taskDTO->getTaskState(),
                $projectTask);

            //now we try to find the user that is creating the task
            $userToCheck = $this->userRepository->findById($taskDTO->getUserID());

            if ($userToCheck == null) {
                throw new Exception("No se pudo encontrar el usuario con ID: " . $taskDTO->getUserID());
            }

            //I get all the links of that given project so I can check User privileges
            $userLinks = $userToCheck->getLinks();

            foreach ($userLinks as $link) {

                //if the creatable we find in the user's link is the same as the project one
                if(  $link->getCreatable()->getId() == $projectTask->getId() ){

                    //if the user of the link is the admin of the project (there is only one)
                    //we just need to create one link for the task
                    if ($link->getRole() == RoleType::ADMIN){

                        //create the link domain object
                        $linkToAdd = new Link(null,
                            new DateTimeImmutable('now'),
                            RoleType::ADMIN,
                            $task,
                            $userToCheck);

                        //we try to persist the task
                        $taskID = $this->taskRepository->addTask($task);

                        //we persist the link
                        $this->linkRepository->save($linkToAdd);

                        return $taskID;

                    //if the user of the link is NOT the admin of the project
                    //we need to create an extra link for the project's admin
                    }elseif ( $link->getRole() == RoleType::EDITOR){


                        //we get the links from the project we're adding a task for
                        $projectLinks = $projectTask->getLinks();

                        //we iterate those links
                        foreach ($projectLinks as $projectLink){


                            if ($projectLink->getRole() == RoleType::ADMIN){

                                //create the link domain object
                                $linkToAdd = new Link(null,
                                    new DateTimeImmutable('now'),
                                    RoleType::ADMIN,
                                    $task,
                                    $userToCheck);

                                //we try to persist the task
                                $taskID = $this->taskRepository->addTask($task);

                                //we persist the link
                                $this->linkRepository->save($linkToAdd);

                                //create a link on the task for the project's admin
                                $linkToAdd = new Link(null,
                                    new DateTimeImmutable('now'),
                                    RoleType::ADMIN,
                                    $task,
                                    $projectLink->getUser());

                                //we persist the link
                                $this->linkRepository->save($linkToAdd);

                                return $taskID;

                            }
                        }
                    }
                }
            }
            throw new Exception("el usuario: " . $userToCheck->getName() . " no esta autorizado para este proyecto: " . $projectTask->getTitle());


        } catch (Exception $e) {

            echo "No se pudo dar de alta la tarea: " . $taskDTO->getTitle();
            echo "\n";
            echo "-----------------------------------------------------------------------------------------------------" . "\n";
            echo "Razón: " . $e->getMessage();
            throw $e;
            //return 0;
        }
    }


    /**
     * @throws Exception
     */
    public function updateTask(TaskDTO $taskDTO, int $userId): void{


        try {
            // base null controls
            if($this->userRepository->findById($userId) == null){
                throw new Exception("No se pudo encontrar el usuario con ID: " . $userId);
            }
            if($this->taskRepository->findById($taskDTO->getId()) == null){
                throw new Exception("No se pudo encontrar la tarea con ID: " . $taskDTO->getId());
            }

            $task = $this->taskRepository->findById($taskDTO->getId());
            $task->setTitle($taskDTO->getTitle());
            $task->setDescription($taskDTO->getDescription());
            $task->setLimitDate($taskDTO->getLimitDate());
            $task->setTaskState($taskDTO->getTaskState());

            foreach ($task->getLinks() as $taskLink) {
                if ($taskLink->getRole() == RoleType::ADMIN) {
                    if ($taskLink->getUser()->getId() == $userId) {
                        $this->taskRepository->updateTask($task);
                        return;
                    }
                }
            }

            throw new Exception("el usuario: " . $userId . " no esta autorizado para editar esta tarea: " . $taskDTO->getId());

        }catch (Exception $e){

            echo "No se pudo editar la tarea: " . $taskDTO->getId();
            echo "\n";
            echo "-----------------------------------------------------------------------------------------------------" . "\n";
            echo "Razón: " . $e->getMessage();
            throw $e;
        }
    }




    /**
     * @throws OptimisticLockException
     * @throws ORMException
     * @throws Exception
     */
    public function deleteTask(int $taskId, int $userId): void
    {

        try {
            //we find the user that wants to delete the task
            $userToCheck = $this->userRepository->findById($userId);

            if ($userToCheck == null) {
                throw new Exception("No se pudo encontrar el usuario con ID: " . $userId);
            }

            //we find the task we will be deleting
            $taskToDelete = $this->taskRepository->findById($taskId);

            if ($taskToDelete == null) {
                throw new Exception("No se pudo encontrar la tarea con ID: " . $taskId);
            }

            //I get all the links of the user
            $userLinks = $userToCheck->getLinks();



            foreach ($userLinks as $link) {

                //if the creatable of the user we find is the same as the task's ID
                if(  $link->getCreatable()->getId() == $taskToDelete->getId() ){

                    //check privileges
                    if ($link->getRole() == RoleType::ADMIN){

                        //we DELETE the task
                        $this->taskRepository->deleteTask($taskToDelete);
                        return;
                    }
                    else{
                        throw new Exception("No se tienen los permisos correspondientes para eliminar la tarea con ID: " . $taskToDelete->getId());
                    }
                }
            }

            throw new Exception("No se encontró el vinculo de la tarea con ID: " . $taskId . " para el usuario con ID: " . $userToCheck->getId());

        }catch (Exception $e){
            throw new Exception($e->getMessage());
        }
    }
}